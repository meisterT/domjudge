<?php declare(strict_types=1);

namespace App\Controller;

use App\Entity\Language;
use App\Service\AuthorizedUserService;
use App\Service\ConfigurationService;
use App\Service\DOMJudgeService;
use App\Service\EventLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Extra\Markdown\MarkdownRuntime;

#[Route(path: '')]
class RootController extends BaseController
{
    private const EXAMPLE_FILES = [
        'c'       => ['file' => 'example.c',    'name' => 'C',        'lang' => 'c'],
        'cpp'     => ['file' => 'example.cc',   'name' => 'C++',      'lang' => 'cpp'],
        'java'    => ['file' => 'example.java', 'name' => 'Java',     'lang' => 'java'],
        'kotlin'  => ['file' => 'example.kt',   'name' => 'Kotlin',   'lang' => 'kotlin'],
        'python3' => ['file' => 'example.py',   'name' => 'Python',   'lang' => 'python'],
        'csharp'  => ['file' => 'example.cs',   'name' => 'C#',       'lang' => 'csharp'],
        'golang'  => ['file' => 'example.go',   'name' => 'Go',       'lang' => 'go'],
        'haskell' => ['file' => 'example.hs',   'name' => 'Haskell',  'lang' => 'haskell'],
        'pascal'  => ['file' => 'example.pas',  'name' => 'Pascal',   'lang' => 'pascal'],
    ];

    private const DEFAULT_EXAMPLE_LANGUAGES = ['c', 'cpp', 'java', 'kotlin', 'python3'];

    public function __construct(
        protected readonly AuthorizedUserService $authService,
        protected readonly ConfigurationService $config,
        DOMJudgeService $dj,
        EntityManagerInterface $em,
        EventLogService $eventLog,
        KernelInterface $kernel,
    ) {
        parent::__construct($em, $eventLog, $dj, $kernel);
    }

    #[Route(path: '', name: 'root')]
    public function redirectAction(AuthorizationCheckerInterface $authorizationChecker): RedirectResponse
    {
        if ($authorizationChecker->isGranted('IS_AUTHENTICATED_FULLY')) {
            if ($this->authService->checkRole('jury')) {
                return $this->redirectToRoute('jury_index');
            }
            if ($this->authService->checkRole('team', false)) {
                return $this->redirectToRoute('team_index');
            }
            if ($this->authService->checkRole('balloon')) {
                return $this->redirectToRoute('jury_balloons_legacy');
            }
            if ($this->authService->checkRole('clarification_rw')) {
                return $this->redirectToRoute('jury_clarifications_legacy');
            }
        }
        return $this->redirectToRoute('public_index');
    }

    #[Route(path: '/doc/manual/html/index.html', name: 'redirect_docsadmin')]
    public function docsadmin(): RedirectResponse {
        return $this->redirect('https://www.domjudge.org/docs/manual/index.html');
    }

    #[Route(path: '/doc/manual/domjudge-team-manual.pdf', name: 'redirect_docsteam')]
    public function docsteam(): RedirectResponse {
        return $this->redirectToRoute('team_manual');
    }

    #[Route(path: '/team/manual', name: 'team_manual', methods: ['GET'])]
    public function teamManualAction(): Response
    {
        $user = $this->authService->getUser();
        if ($this->authService->checkRole('team', false) && $user?->getTeam() !== null) {
            $layout = 'team';
            $contest = $this->dj->getCurrentContest($user->getTeam()->getTeamid());
        } elseif ($this->isGranted('ROLE_JURY') || $this->isGranted('ROLE_ADMIN')
            || $this->isGranted('ROLE_BALLOON') || $this->isGranted('ROLE_CLARIFICATION_RW')) {
            $layout = 'jury';
            $contest = $this->dj->getCurrentContest();
        } else {
            $layout = 'public';
            $contest = $this->dj->getCurrentContest(onlyPublic: true);
        }

        $examplesDir = $this->kernel->getProjectDir() . '/resources/code-examples';
        $exampleIn = $this->readExampleFile($examplesDir, 'example.in');
        $exampleOut = $this->readExampleFile($examplesDir, 'example.out');

        /** @var Language[] $allowedLanguages */
        $allowedLanguages = $this->dj->getAllowedLanguagesForContest($contest);
        $allowedById = [];
        foreach ($allowedLanguages as $lang) {
            $allowedById[$lang->getExternalid()] = $lang;
        }

        $examples = [];
        foreach (self::EXAMPLE_FILES as $extId => $meta) {
            if (isset($allowedById[$extId])) {
                $code = $this->readExampleFile($examplesDir, $meta['file']);
                if ($code !== null) {
                    $examples[] = [
                        'name' => $allowedById[$extId]->getName(),
                        'lang' => $meta['lang'],
                        'file' => $meta['file'],
                        'code' => rtrim($code),
                    ];
                }
            }
        }

        if ($examples === []) {
            foreach (self::DEFAULT_EXAMPLE_LANGUAGES as $extId) {
                $meta = self::EXAMPLE_FILES[$extId];
                $code = $this->readExampleFile($examplesDir, $meta['file']);
                if ($code !== null) {
                    $examples[] = [
                        'name' => $meta['name'],
                        'lang' => $meta['lang'],
                        'file' => $meta['file'],
                        'code' => rtrim($code),
                    ];
                }
            }
        }

        return $this->render('team/manual.html.twig', [
            'layout'                  => $layout,
            'contest'                 => $contest,
            'sourcesize_limit'        => $this->config->get('sourcesize_limit'),
            'sourcefiles_limit'       => $this->config->get('sourcefiles_limit'),
            'script_timelimit'        => $this->config->get('script_timelimit'),
            'process_limit'           => $this->config->get('process_limit'),
            'compile_penalty'         => (bool)$this->config->get('compile_penalty'),
            'show_compile'            => (int)$this->config->get('show_compile'),
            'show_limits_on_team_page'=> (bool)$this->config->get('show_limits_on_team_page'),
            'show_public_stats'       => (bool)$this->config->get('show_public_stats'),
            'example_in'              => $exampleIn !== null ? rtrim($exampleIn) : "3\nworld\nJan\nSantaClaus",
            'example_out'             => $exampleOut !== null ? rtrim($exampleOut) : "Hello world!\nHello Jan!\nHello SantaClaus!",
            'examples'                => $examples,
        ]);
    }

    private function readExampleFile(string $dir, string $filename): ?string
    {
        $path = $dir . '/' . $filename;
        if (is_readable($path)) {
            $contents = file_get_contents($path);
            return $contents !== false ? $contents : null;
        }
        return null;
    }

    #[Route(path: '/markdown-preview', name: 'markdown_preview', methods: ['POST'])]
    public function markdownPreview(
        Request $request,
        #[Autowire(service: 'twig.runtime.markdown')]
        MarkdownRuntime $markdownRuntime,
        HtmlSanitizerInterface $appClarificationSanitizer,
    ): JsonResponse {
        $message = $request->request->get('message');
        if ($message === null) {
            throw new BadRequestHttpException('A message is required');
        }
        return new JsonResponse(['html' => $appClarificationSanitizer->sanitize($markdownRuntime->convert($message))]);
    }
}
