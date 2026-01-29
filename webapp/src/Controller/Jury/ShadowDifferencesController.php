<?php declare(strict_types=1);

namespace App\Controller\Jury;

use App\Controller\BaseController;
use App\DataTransferObject\SubmissionRestriction;
use App\Entity\Problem;
use App\Service\ConfigurationService;
use App\Service\DOMJudgeService;
use App\Service\EventLogService;
use App\Service\SubmissionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route(path: '/jury/shadow-differences')]
class ShadowDifferencesController extends BaseController
{
    public function __construct(
        DOMJudgeService $dj,
        protected readonly ConfigurationService $config,
        protected readonly SubmissionService $submissions,
        protected readonly RequestStack $requestStack,
        EntityManagerInterface $em,
        protected readonly EventLogService $eventLogService,
        KernelInterface $kernel,
    ) {
        parent::__construct($em, $eventLogService, $dj, $kernel);
    }

    /**
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    #[Route(path: '', name: 'jury_shadow_differences')]
    public function indexAction(
        Request $request,
        #[MapQueryParameter(name: 'view')]
        ?string $viewFromRequest = null,
        #[MapQueryParameter(name: 'verificationview')]
        ?string $verificationViewFromRequest = null,
        #[MapQueryParameter]
        string $external = 'all',
        #[MapQueryParameter]
        string $local = 'all',
    ): Response {
        $contest = $this->dj->getCurrentContest();
        if (!$contest) {
            $this->addFlash('danger', 'Shadow differences need an active contest.');
            return $this->redirectToRoute('jury_index');
        }

        if (!$contest->isExternalSourceEnabled()) {
            $this->addFlash('warning', 'Shadow mode is not enabled for this contest, please configure it first.');
            return $this->redirect($this->generateUrl('jury_contest_edit', ['contestId' => $contest->getCid()]) . '#externalSourceEnabled');
        }

        // Close the session, as this might take a while and we don't need the session below.
        $this->requestStack->getSession()->save();

        $verdicts = $this->config->getVerdicts(['final', 'error', 'external', 'in_progress']);

        $used         = [];
        $verdictTable = [];
        // Pre-fill $verdictTable to get a consistent ordering.
        foreach ($verdicts as $verdict => $abbrev) {
            foreach ($verdicts as $verdict2 => $abbrev2) {
                $verdictTable[$verdict][$verdict2] = 0;
            }
        }

        // Helper function to add verdicts discovered in query results.
        $addVerdict = function (string $unknownVerdict) use ($verdicts, &$verdictTable): void {
            // Add column to existing rows.
            foreach ($verdicts as $verdict => $abbreviation) {
                $verdictTable[$verdict][$unknownVerdict] = 0;
            }
            // Add verdict to known verdicts.
            $verdicts[$unknownVerdict] = $unknownVerdict;
            // Add row.
            $verdictTable[$unknownVerdict] = [];
            foreach ($verdicts as $verdict => $abbreviation) {
                $verdictTable[$unknownVerdict][$verdict] = 0;
            }
        };

        // Build the verdict matrix using an aggregate query instead of loading all entities.
        $verdictCounts = $this->em->getConnection()->executeQuery(
            'SELECT
                CASE WHEN ej.result IS NOT NULL THEN ej.result ELSE :judging END AS external_result,
                CASE WHEN s.import_error IS NOT NULL THEN :importError WHEN j.result IS NOT NULL THEN j.result ELSE :judging END AS local_result,
                COUNT(*) AS cnt
            FROM submission s
            LEFT JOIN external_judgement ej ON ej.submitid = s.submitid AND ej.valid = 1
            LEFT JOIN judging j ON j.submitid = s.submitid AND j.valid = 1
            WHERE s.cid = :contest
                AND s.externalid IS NOT NULL
                AND s.expected_results IS NULL
            GROUP BY external_result, local_result',
            [
                'contest' => $contest->getCid(),
                'judging' => 'judging',
                'importError' => 'import-error',
            ]
        )->fetchAllAssociative();

        foreach ($verdictCounts as $row) {
            $externalResult = $row['external_result'];
            $localResult = $row['local_result'];
            $cnt = (int)$row['cnt'];

            // Add verdicts to data structures if they are unknown up to now.
            foreach ([$externalResult, $localResult] as $result) {
                if (!array_key_exists($result, $verdicts)) {
                    $addVerdict($result);
                }
            }

            $used[$externalResult] = true;
            $used[$localResult]    = true;

            $verdictTable[$externalResult][$localResult] = $cnt;
        }

        // Collect score change data using a targeted query (only for scoring problems).
        $scoreChanges = [];
        $hasScoringProblems = false;
        $maxScore = 0;

        $scoreRows = $this->em->getConnection()->executeQuery(
            'SELECT
                s.externalid AS submitId,
                COALESCE(t.display_name, t.name) AS teamName,
                t.externalid AS teamId,
                p.name AS problemName,
                p.externalid AS problemId,
                ej.score AS externalScore,
                j.score AS localScore
            FROM submission s
            INNER JOIN external_judgement ej ON ej.submitid = s.submitid AND ej.valid = 1 AND ej.result IS NOT NULL
            INNER JOIN judging j ON j.submitid = s.submitid AND j.valid = 1 AND j.result IS NOT NULL
            INNER JOIN problem p ON p.probid = s.probid
            INNER JOIN team t ON t.teamid = s.teamid
            WHERE s.cid = :contest
                AND s.externalid IS NOT NULL
                AND s.expected_results IS NULL
                AND (p.types & :scoringType) != 0',
            [
                'contest' => $contest->getCid(),
                'scoringType' => Problem::TYPE_SCORING,
            ]
        )->fetchAllAssociative();

        $contestExternalId = $contest->getExternalid();
        foreach ($scoreRows as $row) {
            $hasScoringProblems = true;
            $externalScore = (float)$row['externalScore'];
            $localScore = (float)$row['localScore'];
            $delta = $localScore - $externalScore;
            $absDelta = abs($delta);
            $maxScore = max($maxScore, $externalScore, $localScore);

            $scoreChanges[] = [
                'submitId' => $row['submitId'],
                'contestId' => $contestExternalId,
                'teamName' => $row['teamName'],
                'teamId' => $row['teamId'],
                'problemName' => $row['problemName'],
                'problemId' => $row['problemId'],
                'oldScore' => $externalScore,
                'newScore' => $localScore,
                'delta' => $delta,
                'absDelta' => $absDelta,
            ];
        }

        $viewTypes = [0 => 'unjudged local', 1 => 'unjudged external', 2 => 'diff', 3 => 'all'];
        $view      = 2;
        if ($viewFromRequest) {
            $index = array_search($viewFromRequest, $viewTypes);
            if ($index !== false) {
                $view = $index;
            }
        }

        $verificationViewTypes = [0 => 'all', 1 => 'unverified', 2 => 'verified'];
        $verificationView      = 0;
        if ($verificationViewFromRequest) {
            $index = array_search($verificationViewFromRequest, $verificationViewTypes);
            if ($index !== false) {
                $verificationView = $index;
            }
        }

        $restrictions = new SubmissionRestriction(withExternalId: true);
        if ($viewTypes[$view] == 'unjudged local') {
            $restrictions->judged = false;
        }
        if ($viewTypes[$view] == 'unjudged external') {
            $restrictions->externallyJudged = false;
        }
        if ($viewTypes[$view] == 'diff') {
            $restrictions->externalDifference = true;
        }
        if ($verificationViewTypes[$verificationView] == 'unverified') {
            $restrictions->externallyVerified = false;
        }
        if ($verificationViewTypes[$verificationView] == 'verified') {
            $restrictions->externallyVerified = true;
        }
        if ($external !== 'all') {
            $restrictions->externalResult = $external;
        }
        if ($local !== 'all') {
            $restrictions->result = $local;
        }

        [$submissions, $submissionCounts] = $this->submissions->getSubmissionList(
            $this->dj->getCurrentContests(honorCookie: true),
            $restrictions,
            page: $request->query->getInt('page', 1),
            showShadowUnverified: true
        );

        $data = [
            'verdicts' => $verdicts,
            'used' => $used,
            'verdictTable' => $verdictTable,
            'viewTypes' => $viewTypes,
            'view' => $view,
            'verificationViewTypes' => $verificationViewTypes,
            'verificationView' => $verificationView,
            'submissions' => $submissions,
            'submissionCounts' => $submissionCounts,
            'external' => $external,
            'local' => $local,
            'showExternalResult' => true,
            'showContest' => false,
            'showTestcases' => true,
            'showExternalTestcases' => true,
            'refresh' => [
                'after' => 15,
                'url' => $request->getRequestUri(),
                'ajax' => true,
            ],
            'hasScoringProblems' => $hasScoringProblems,
            'scoreChanges' => $scoreChanges,
            'maxScore' => $maxScore,
        ];
        if ($request->isXmlHttpRequest()) {
            $data['ajax'] = true;
            return $this->render('jury/partials/shadow_submissions.html.twig', $data);
        } else {
            return $this->render('jury/shadow_differences.html.twig', $data);
        }
    }
}
