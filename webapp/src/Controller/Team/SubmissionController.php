<?php declare(strict_types=1);

namespace App\Controller\Team;

use App\Controller\BaseController;
use App\Entity\Contest;
use App\Entity\Judging;
use App\Entity\Language;
use App\Entity\Problem;
use App\Entity\Submission;
use App\Entity\SubmissionSource;
use App\Entity\Team;
use App\Entity\Testcase;
use App\Form\Type\SubmitProblemType;
use App\Service\AuthorizedUserService;
use App\Service\ConfigurationService;
use App\Service\DOMJudgeService;
use App\Service\EventLogService;
use App\Service\SubmissionService;
use App\Utils\Utils;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\Query\Expr\Join;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_TEAM')]
#[IsGranted(
    new Expression('user.getTeam() !== null'),
    message: 'You do not have a team associated with your account.'
)]
#[Route(path: '/team')]
class SubmissionController extends BaseController
{
    final public const NEVER_SHOW_COMPILE_OUTPUT = 0;
    final public const ONLY_SHOW_COMPILE_OUTPUT_ON_ERROR = 1;
    final public const ALWAYS_SHOW_COMPILE_OUTPUT = 2;

    public function __construct(
        protected readonly AuthorizedUserService $authService,
        EntityManagerInterface $em,
        protected readonly SubmissionService $submissionService,
        protected readonly EventLogService $eventLogService,
        DOMJudgeService $dj,
        protected readonly ConfigurationService $config,
        protected readonly FormFactoryInterface $formFactory,
        KernelInterface $kernel,
    ) {
        parent::__construct($em, $eventLogService, $dj, $kernel);
    }

    #[Route(path: '/submit/{problem}', name: 'team_submit')]
    public function createAction(Request $request, ?Problem $problem = null): Response
    {
        $user    = $this->authService->getUser();
        $team    = $user->getTeam();
        $contest = $this->dj->getCurrentContest($user->getTeam()->getTeamid());
        $data = ['languages' => []];
        if ($problem !== null) {
            $data['problem'] = $problem;
            $data['languages'] = $problem->getLanguages()->toArray();
        }

        $submitMethods = $this->config->get('submit_methods');

        $uploadForm = null;
        $pasteForm = null;

        if (in_array('upload', $submitMethods)) {
            $uploadForm = $this->formFactory
                ->createNamedBuilder('submit_problem', SubmitProblemType::class, $data, ['submission_mode' => 'upload'])
                ->setAction($this->generateUrl('team_submit'))
                ->getForm();
        }

        // Only create the paste form for the full page (not the modal)
        if (in_array('paste', $submitMethods) && !$request->isXmlHttpRequest()) {
            $pasteForm = $this->formFactory
                ->createNamedBuilder('submit_problem_paste', SubmitProblemType::class, $data, ['submission_mode' => 'paste'])
                ->setAction($this->generateUrl('team_submit'))
                ->getForm();
        }

        // Handle upload form submission
        if ($uploadForm !== null) {
            $uploadForm->handleRequest($request);
            if ($uploadForm->isSubmitted() && $uploadForm->isValid()) {
                return $this->handleSubmission($uploadForm, $team, $contest, 'upload');
            }
        }

        // Handle paste form submission
        if ($pasteForm !== null) {
            $pasteForm->handleRequest($request);
            if ($pasteForm->isSubmitted() && $pasteForm->isValid()) {
                return $this->handleSubmission($pasteForm, $team, $contest, 'paste');
            }
        }

        $templateData = [
            'problem' => $problem,
            'upload_form' => $uploadForm?->createView(),
            'paste_form' => $pasteForm?->createView(),
            'submit_methods' => $submitMethods,
        ];
        $templateData['validFilenameRegex'] = SubmissionService::FILENAME_REGEX;
        if ($contest && $team) {
            $templateData['rateLimitStatus'] = $this->submissionService->getRateLimitStatus($team, $contest);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->render('team/submit_modal.html.twig', $templateData);
        } else {
            return $this->render('team/submit.html.twig', $templateData);
        }
    }

    private function handleSubmission(FormInterface $form, Team $team, ?Contest $contest, string $mode): Response
    {
        if ($contest === null) {
            $this->addFlash('danger', 'No active contest');
            return $this->redirectToRoute('team_index');
        }
        if (!$this->authService->checkRole('jury') && !$contest->getFreezeData()->started()) {
            $this->addFlash('danger', 'Contest has not yet started');
            return $this->redirectToRoute('team_index');
        }

        /** @var Problem $problem */
        $problem = $form->get('problem')->getData();
        /** @var Language $language */
        $language = $form->get('language')->getData();
        $entryPoint = $form->get('entry_point')->getData() ?: null;

        $tmpFile = null;
        if ($mode === 'paste') {
            $result = $this->buildPasteFiles($problem, $language, $contest, $form->get('code_content')->getData(), $entryPoint);
            if ($result === null) {
                return $this->redirectToRoute('team_index');
            }
            [$files, $tmpFile] = $result;
        } else {
            /** @var UploadedFile[]|UploadedFile $files */
            $files = $form->get('code')->getData();
            if (!is_array($files)) {
                $files = [$files];
            }
        }

        try {
            $message = '';
            $submission = $this->submissionService->submitSolution(
                $team, $this->authService->getUser(), $problem->getProbid(), $contest, $language, $files, SubmissionSource::TEAM_PAGE, null,
                null, $entryPoint, null, null, $message
            );

            if ($submission) {
                $this->addFlash('success', 'Submission done! Watch for the verdict in the list below.');
            } else {
                $this->addFlash('danger', $message);
            }
        } finally {
            if ($tmpFile !== null && file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        return $this->redirectToRoute('team_index');
    }

    /**
     * Build an UploadedFile from pasted code content.
     *
     * @return array{UploadedFile[], string}|null [files, tmpFilePath], or null if the code content is empty
     */
    private function buildPasteFiles(Problem $problem, Language $language, Contest $contest, ?string $codeContent, ?string $entryPoint): ?array
    {
        if (empty($codeContent)) {
            $this->addFlash('danger', 'No code provided.');
            return null;
        }

        // Determine filename — find the contest problem for the current contest
        $contestProblem = $problem->getContestProblems()->filter(
            fn($cp) => $cp->getContest() === $contest
        )->first();
        $extensions = $language->getExtensions();
        $extension = $extensions[0] ?? 'txt';

        if ($language->getRequireEntryPoint() && $entryPoint !== null) {
            $filename = $entryPoint . '.' . $extension;
        } elseif ($contestProblem !== false) {
            $filename = $contestProblem->getShortname() . '.' . $extension;
        } else {
            $filename = 'submission.' . $extension;
        }

        $filename = SubmissionService::sanitizeFilename($filename);

        // Write to temp file and create UploadedFile
        $tmpFile = tempnam(sys_get_temp_dir(), 'dj_paste_');
        file_put_contents($tmpFile, $codeContent);
        $uploadedFile = new UploadedFile($tmpFile, $filename, null, null, true);

        return [[$uploadedFile], $tmpFile];
    }

    /**
     * @throws NonUniqueResultException
     */
    #[Route(path: '/submission/{submitId}', name: 'team_submission')]
    public function viewAction(Request $request, string $submitId): Response
    {
        $verificationRequired = (bool)$this->config->get('verification_required');
        $showCompile          = $this->config->get('show_compile');
        $showSampleOutput     = (bool)$this->config->get('show_sample_output');
        $allowDownload        = (bool)$this->config->get('allow_team_submission_download');
        $showTooLateResult    = $this->config->get('show_too_late_result');
        $showLimits           = (bool)$this->config->get('show_limits_on_team_page');
        $user                 = $this->authService->getUser();
        $team                 = $user->getTeam();
        $contest              = $this->dj->getCurrentContest($team->getTeamid());
        if (!$contest) {
            throw new NotFoundHttpException('No active contest');
        }
        /** @var Judging|null $judging */
        $judging = $this->em->createQueryBuilder()
            ->from(Judging::class, 'j')
            ->join('j.submission', 's')
            ->join('s.contest_problem', 'cp')
            ->join('cp.problem', 'p')
            ->join('s.language', 'l')
            ->select('j', 's', 'cp', 'p', 'l')
            ->andWhere('s.externalid = :submitId')
            ->andWhere('s.contest = :contest')
            ->andWhere('j.valid = 1')
            ->andWhere('s.team = :team')
            ->setParameter('submitId', $submitId)
            ->setParameter('contest', $contest)
            ->setParameter('team', $team)
            ->getQuery()
            ->getOneOrNullResult();

        // Update seen status when viewing submission.
        if ($judging && $judging->getSubmission()->getSubmittime() < $contest->getEndtime() &&
            (!$verificationRequired || $judging->getVerified())) {
            $judging->setSeen(true);
            $this->em->flush();
        }

        // Only show verdicts for all testcases once the judging has finished to avoid
        // exposing partial or out-of-order results while judging is still in progress.
        $showAllTestcases = (bool)$this->config->get('show_all_testcase_verdicts')
            && $judging !== null
            && $judging->getResult() !== null;

        $runs = [];
        if (($showSampleOutput || $showAllTestcases) && $judging !== null && $judging->getResult() !== 'compiler-error') {
            $queryBuilder = $this->em->createQueryBuilder()
                ->from(Testcase::class, 't')
                ->leftJoin('t.judging_runs', 'jr', Join::WITH, 'jr.judging = :judging')
                ->select('t', 'jr')
                ->andWhere('t.problem = :problem')
                ->setParameter('judging', $judging)
                ->setParameter('problem', $judging->getSubmission()->getProblem())
                ->orderBy('t.ranknumber');

            if ($showLimits) {
                $queryBuilder
                    ->leftJoin('jr.output', 'jro')
                    ->addSelect('jro.metadata AS metadata');
            }

            if ($showAllTestcases) {
                $queryBuilder->andWhere('jr.runresult IS NOT NULL');
            } else {
                $queryBuilder->andWhere('t.sample = 1');
            }

            if ($showSampleOutput) {
                $outputDisplayLimit    = (int)$this->config->get('output_display_limit');
                $outputTruncateMessage = sprintf("\n[output display truncated after %d B]\n", $outputDisplayLimit);

                $queryBuilder->leftJoin('jr.output', 'jros', Join::WITH, 't.sample = 1');

                if ($outputDisplayLimit < 0) {
                    $queryBuilder
                        ->addSelect('jros.output_run AS output_run')
                        ->addSelect('jros.output_error AS output_error')
                        ->addSelect('jros.team_message AS team_message');
                } else {
                    $queryBuilder
                        ->addSelect('TRUNCATE(jros.output_run, :outputDisplayLimit, :outputTruncateMessage) AS output_run')
                        ->addSelect('TRUNCATE(jros.output_error, :outputDisplayLimit, :outputTruncateMessage) AS output_error')
                        ->addSelect('TRUNCATE(jros.team_message, :outputDisplayLimit, :outputTruncateMessage) AS team_message')
                        ->setParameter('outputDisplayLimit', $outputDisplayLimit)
                        ->setParameter('outputTruncateMessage', $outputTruncateMessage);
                }
            }

            $runs = array_map(
                static fn (mixed $run): array => is_array($run) ? $run : [0 => $run],
                $queryBuilder->getQuery()->getResult()
            );
        }

        $metadata = [];
        if ($showLimits) {
            foreach ($runs as $index => $runData) {
                if (!empty($runData['metadata'])) {
                    $parsed = Utils::parseMetadata($runData['metadata']);
                    if (isset($parsed['memory-bytes'])) {
                        $metadata[$index] = [
                            'memory-bytes' => (int)$parsed['memory-bytes'],
                        ];
                    }
                }
            }
        }

        $actuallyShowCompile = $showCompile == self::ALWAYS_SHOW_COMPILE_OUTPUT
            || ($showCompile == self::ONLY_SHOW_COMPILE_OUTPUT_ON_ERROR && $judging?->getResult() === 'compiler-error');

        $data = [
            'judging' => $judging,
            'verificationRequired' => $verificationRequired,
            'showCompile' => $actuallyShowCompile,
            'allowDownload' => $allowDownload,
            'showSampleOutput' => $showSampleOutput,
            'showAllTestcases' => $showAllTestcases,
            'runs' => $runs,
            'metadata' => $metadata,
            'showTooLateResult' => $showTooLateResult,
            'thumbnailSize' => $this->config->get('thumbnail_size'),
            'showLimits' => $showLimits,
        ];
        if ($actuallyShowCompile) {
            $data['size'] = 'xl';
        }

        if ($request->isXmlHttpRequest()) {
            return $this->render('team/submission_modal.html.twig', $data);
        } else {
            return $this->render('team/submission.html.twig', $data);
        }
    }

    /**
     * @throws NonUniqueResultException
     */
    #[Route(path: '/submission/{submitId}/download', name: 'team_submission_download')]
    public function downloadAction(string $submitId): Response
    {
        $allowDownload = (bool)$this->config->get('allow_team_submission_download');
        if (!$allowDownload) {
            throw new NotFoundHttpException('Submission download not allowed');
        }

        $user = $this->authService->getUser();
        $team = $user->getTeam();
        $contest = $this->dj->getCurrentContest($team->getTeamid());
        if (!$contest) {
            throw new NotFoundHttpException('No active contest');
        }
        /** @var Submission|null $submission */
        $submission = $this->em->createQueryBuilder()
            ->from(Submission::class, 's')
            ->join('s.files', 'f')
            ->select('s, f')
            ->andWhere('s.externalid = :submitId')
            ->andWhere('s.contest = :contest')
            ->andWhere('s.team = :team')
            ->setParameter('submitId', $submitId)
            ->setParameter('contest', $contest)
            ->setParameter('team', $team)
            ->getQuery()
            ->getOneOrNullResult();

        if ($submission === null) {
            throw new NotFoundHttpException(sprintf('Submission with ID \'%s\' not found',
                $submitId));
        }

        if ($this->submissionService->getSubmissionFileCount($submission) === 1) {
            return $this->submissionService->getSubmissionFileResponse($submission);
        }

        return $this->submissionService->getSubmissionZipResponse($submission);
    }
}
