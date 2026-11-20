<?php declare(strict_types=1);

namespace App\Controller\Jury;

use App\Attribute\ReleaseSessionLock;
use App\Controller\BaseController;
use App\Doctrine\DBAL\Types\JudgeTaskType;
use App\Entity\Judgehost;
use App\Entity\JudgeTask;
use App\Entity\Judging;
use App\Entity\JudgingRun;
use App\Form\Type\JudgehostsType;
use App\Service\ConfigurationService;
use App\Service\DOMJudgeService;
use App\Service\EventLogService;
use App\Utils\Utils;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @phpstan-type TimelineRunRow array{
 *     judgehostid: int,
 *     startTime: string|float,
 *     endtime: string|float,
 *     judgingid: int,
 *     submitid: int,
 *     submit_externalid: ?string,
 *     cid: int,
 *     contest_shortname: string,
 *     contest_externalid: string,
 *     probid: int,
 *     problem_name: string,
 *     runresult: ?string,
 *     jobid: ?int,
 * }
 * @phpstan-type TimelineSegment array{
 *     jh: int,
 *     c: int,
 *     p: int,
 *     s: string,
 *     j: int,
 *     st: float,
 *     et: float,
 *     r: ?string,
 *     n: int,
 * }
 */
#[IsGranted('ROLE_JURY')]
#[Route(path: '/jury/judgehosts')]
class JudgehostController extends BaseController
{
    /** Upper bound on the number of testcase runs fetched for the timeline. */
    private const TIMELINE_MAX_RUNS = 20000;

    /** Supported timeline lookback windows in seconds. */
    private const TIMELINE_WINDOWS = [
        '15m' => 15 * 60,
        '1h'  => 60 * 60,
        '6h'  => 6 * 60 * 60,
        '24h' => 24 * 60 * 60,
    ];

    private const TIMELINE_DEFAULT_WINDOW = '1h';

    /** Maximum idle gap (in seconds) between runs of the same judging to coalesce into one bar. */
    private const TIMELINE_COALESCE_GAP_SECONDS = 2.0;

    // Note: when adding or modifying routes, make sure they do not clash with the /judgehosts/{hostname} route.

    public function __construct(
        EntityManagerInterface $em,
        DOMJudgeService $dj,
        protected readonly ConfigurationService $config,
        EventLogService $eventLog,
        KernelInterface $kernel,
    ) {
        parent::__construct($em, $eventLog, $dj, $kernel);
    }

    #[Route(path: '', name: 'jury_judgehosts')]
    #[ReleaseSessionLock]
    public function indexAction(Request $request): Response
    {
        /** @var Judgehost[] $judgehosts */
        $judgehosts = $this->em->createQueryBuilder()
            ->from(Judgehost::class, 'j')
            ->select('j')
            ->andWhere('j.hidden = 0')
            ->getQuery()->getResult();

        $table_fields = [
            'judgehostid' => ['title' => 'ID'],
            'hostname' => ['title' => 'hostname'],
            'enabled' => ['title' => 'enabled'],
            'status' => ['title' => 'status'],
            'load' => ['title' => 'load (5m/15m/1h/5h)'],
            'last_judgingid' => ['title' => 'last judging'],
        ];

        $now = Utils::now();
        $timings = $this->em->createQueryBuilder()
            ->from(JudgeTask::class, 'jt')
            ->join(JudgingRun::class, 'jr', 'WITH', 'jr.judgetask = jt')
            ->join('jr.judging', 'j')
            ->join('jt.judgehost', 'jh')
            ->select('jh.judgehostid, jr.endtime, jr.startTime')
            ->andWhere('jr.startTime IS NOT NULL')
            ->andWhere('jr.endtime IS NOT NULL')
            ->andWhere('jr.endtime >= :five_hours_ago')
            ->setParameter('five_hours_ago', $now - 5*60*60)
            ->getQuery()
            ->getResult();

        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        $time_warn = $this->config->get('judgehost_warning');
        $time_crit = $this->config->get('judgehost_critical');
        $judgehosts_table = [];
        $all_checked_in_recently = true;
        foreach ($judgehosts as $judgehost) {
            $judgehostdata    = [];
            $judgehostactions = [];
            // Get whatever fields we can from the problem object itself.
            foreach ($table_fields as $k => $v) {
                if ($propertyAccessor->isReadable($judgehost, $k)) {
                    $judgehostdata[$k] = ['value' => $propertyAccessor->getValue($judgehost, $k)];
                }
            }

            // render hostname nicely
            if ($judgehostdata['hostname']['value']) {
                $judgehostdata['hostname']['value'] = Utils::printhost($judgehostdata['hostname']['value']);
            }
            $judgehostdata['hostname']['default']  = '-';
            $judgehostdata['hostname']['cssclass'] = 'text-monospace small';

            if (empty($judgehost->getPolltime())) {
                $status = 'noconn';
                $statustitle = "never checked in";
                $all_checked_in_recently = false;
            } else {
                $relTime = floor(Utils::difftime($now, (float)$judgehost->getPolltime()));
                if ($relTime < $time_warn) {
                    $status = 'ok';
                } elseif ($relTime < $time_crit) {
                    $status = 'warn';
                } else {
                    $status = 'crit';
                    $all_checked_in_recently = false;
                }
                $statustitle = sprintf('last checked in %ss ago',
                                       Utils::printtimediff((float)$judgehost->getPolltime()));
            }

            $lastJobId = $this->em->createQueryBuilder()
                ->from(JudgeTask::class, 'jt')
                ->select('jt.jobid')
                ->andWhere('jt.judgehost = :judgehost')
                ->andWhere('jt.type = :type')
                ->setParameter('judgehost', $judgehost)
                ->setParameter('type', JudgeTaskType::JUDGING_RUN)
                ->orderBy('jt.starttime', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            $judgehostdata['last_judgingid'] = $lastJobId === null ? null : [
                'value' => 'j' . $lastJobId['jobid'],
            ];

            $loads = [0.0, 0.0, 0.0, 0.0];
            $loadMinutes = [60*5, 60*15, 60*60, 60*300];
            $loadStart = [];
            for ($i = 0; $i < 4; $i++) {
                $loadStart[] = $now - $loadMinutes[$i];
            }
            foreach ($timings as $timing) {
                if ($timing['judgehostid'] !== $judgehost->getJudgehostid()) {
                    continue;
                }
                for ($i = 0; $i < 4; $i++) {
                    $start_time = $timing['startTime'];
                    $end_time = $timing['endtime'];
                    $start_time = max($start_time, $loadStart[$i]);
                    if ($start_time < $end_time) {
                        $loads[$i] += ($end_time - $start_time);
                    }
                }
            }
            // Normalize to [0,1] range.
            for ($i = 0; $i < 4; $i++) {
                $loads[$i] = min(1.0, $loads[$i] / $loadMinutes[$i]);
            }
            $judgehostdata['load'] = [
                'value' => sprintf('%.2f / %.2f / %.2f / %.2f', $loads[0], $loads[1], $loads[2], $loads[3]),
                'title' => 'estimated load (5m/15m/1h/5h)',
                'cssclass' => 'text-monospace',
            ];

            $judgehostdata = array_merge($judgehostdata, [
                'status' => [
                    'value' => $status,
                    'title' => $statustitle,
                ],
                'enabled' => [
                    'value' => $judgehost->getEnabled() ? 'yes' : 'no',
                ],
            ]);

            // Create action links
            if ($this->isGranted('ROLE_ADMIN')) {
                if ($judgehost->getEnabled()) {
                    $enableicon = 'pause';
                    $enablecmd  = 'disable';
                    $route      = 'jury_judgehost_disable';
                } else {
                    $enableicon = 'play';
                    $enablecmd  = 'enable';
                    $route      = 'jury_judgehost_enable';
                }
                $judgehostactions[] = [
                    'icon' => $enableicon,
                    'title' => sprintf('%s judgehost', $enablecmd),
                    'link' => $this->generateUrl($route, ['judgehostid' => $judgehost->getJudgehostid()]),
                ];

                $judgehostactions[] = [
                    'icon' => 'trash-alt',
                    'title' => 'delete this judgehost',
                    'link' => $this->generateUrl('jury_judgehost_delete', [
                        'judgehostid' => $judgehost->getJudgehostid(),
                    ]),
                    'ajaxModal' => true,
                ];
            }

            // Save this to our list of rows.
            $judgehosts_table[] = [
                'data' => $judgehostdata,
                'actions' => $judgehostactions,
                'link' => $this->generateUrl('jury_judgehost', ['judgehostid' => $judgehost->getJudgehostid()]),
                'cssclass' => $judgehost->getEnabled() ? '' : 'disabled',
            ];
        }

        usort($judgehosts_table, function (array $a, array $b) {
            return strnatcasecmp($a['data']['hostname']['value'], $b['data']['hostname']['value']);
        });

        $data = [
            'judgehosts' => $judgehosts_table,
            'table_fields' => $table_fields,
            'all_checked_in_recently' => $all_checked_in_recently,
            'refresh' => [
                'after' => 5,
                'url' => $this->generateUrl('jury_judgehosts'),
                'ajax' => true,
            ]
        ];
        if ($request->isXmlHttpRequest()) {
            return $this->render('jury/partials/judgehost_list.html.twig', $data);
        } else {
            return $this->render('jury/judgehosts.html.twig', $data);
        }
    }

    #[Route(path: '/timeline-data', name: 'jury_judgehost_timeline_data', methods: ['GET'])]
    #[ReleaseSessionLock]
    public function timelineDataAction(Request $request): JsonResponse
    {
        $window = (string)$request->query->get('window', self::TIMELINE_DEFAULT_WINDOW);
        if (!isset(self::TIMELINE_WINDOWS[$window])) {
            throw new BadRequestHttpException(sprintf("Invalid timeline window '%s'", $window));
        }

        $now = (float)Utils::now();
        $windowStart = $now - self::TIMELINE_WINDOWS[$window];

        // Fetch JudgingRuns which are the actual work.
        /** @var list<TimelineRunRow> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select(
                'jh.judgehostid, jr.startTime, jr.endtime, j.judgingid, ' .
                's.submitid, s.externalid as submit_externalid, c.cid, c.shortname as contest_shortname, ' .
                'c.externalid as contest_externalid, p.probid, p.name as problem_name, jr.runresult, jt.jobid'
            )
            ->from(JudgingRun::class, 'jr')
            ->join('jr.judgetask', 'jt')
            ->join('jt.judgehost', 'jh')
            ->join('jr.judging', 'j')
            ->join('j.submission', 's')
            ->join('s.contest', 'c')
            ->join('s.problem', 'p')
            ->andWhere('jh.hidden = 0')
            ->andWhere('jr.startTime IS NOT NULL')
            ->andWhere('jr.endtime IS NOT NULL')
            ->andWhere('jr.endtime >= :window_start')
            ->setParameter('window_start', $windowStart)
            // Keep the response bounded on busy systems; the newest runs win.
            ->orderBy('jr.endtime', 'DESC')
            ->setMaxResults(self::TIMELINE_MAX_RUNS + 1)
            ->getQuery()
            ->getArrayResult();

        $truncated = count($rows) > self::TIMELINE_MAX_RUNS;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::TIMELINE_MAX_RUNS);
        }

        $contests = [];
        $problems = [];
        $byJudgehost = [];
        foreach ($rows as $row) {
            $contests[$row['cid']] = [
                'shortname' => $row['contest_shortname'],
                'externalid' => $row['contest_externalid'],
            ];
            $problems[$row['probid']] = $row['problem_name'];
            $byJudgehost[$row['judgehostid']][] = $row;
        }

        $segments = $this->coalesceTimelineRuns($byJudgehost);

        $judgehosts = $this->em->getRepository(Judgehost::class)->findBy(['hidden' => 0]);
        $judgehostMap = [];
        foreach ($judgehosts as $jh) {
            $judgehostMap[$jh->getJudgehostid()] = [
                'hostname' => $jh->getHostname(),
                'enabled' => $jh->getEnabled(),
            ];
        }

        return new JsonResponse([
            'now' => $now,
            'window' => $window,
            'window_start' => $windowStart,
            'truncated' => $truncated,
            'max_runs' => self::TIMELINE_MAX_RUNS,
            'judgehosts' => (object)$judgehostMap,
            'contests' => (object)$contests,
            'problems' => (object)$problems,
            'data' => $segments,
        ]);
    }

    /**
     * Coalesce contiguous testcase runs of the same judging on the same judgehost
     * so busy contests transfer and draw far fewer bars.
     *
     * @param array<int, list<TimelineRunRow>> $byJudgehost
     * @return list<TimelineSegment>
     */
    private function coalesceTimelineRuns(array $byJudgehost): array
    {
        $segments = [];
        foreach ($byJudgehost as $judgehostId => $hostRows) {
            usort(
                $hostRows,
                static fn(array $a, array $b): int => ((float)$a['startTime'] <=> (float)$b['startTime'])
                    ?: ((float)$a['endtime'] <=> (float)$b['endtime'])
            );

            $current = null;
            $currentJudgingId = null;
            foreach ($hostRows as $row) {
                $startTime = (float)$row['startTime'];
                $endTime = max($startTime, (float)$row['endtime']);
                $judgingId = (int)$row['judgingid'];
                $jobId = (int)($row['jobid'] ?? $judgingId);
                $runResult = $row['runresult'];

                if (
                    $current !== null
                    && $currentJudgingId === $judgingId
                    && $current['j'] === $jobId
                    && ($startTime - $current['et']) <= self::TIMELINE_COALESCE_GAP_SECONDS
                ) {
                    if ($endTime > $current['et']) {
                        $current['et'] = $endTime;
                    }
                    $current['n']++;
                    if ($runResult !== null && ($current['r'] === null || ($current['r'] === 'correct' && $runResult !== 'correct'))) {
                        $current['r'] = $runResult;
                    }
                    continue;
                }

                if ($current !== null) {
                    $segments[] = $current;
                }

                $currentJudgingId = $judgingId;
                $current = [
                    'jh' => (int)$judgehostId,
                    'c'  => (int)$row['cid'],
                    'p'  => (int)$row['probid'],
                    's'  => (string)($row['submit_externalid'] ?? $row['submitid']),
                    'j'  => $jobId,
                    'st' => $startTime,
                    'et' => $endTime,
                    'r'  => $runResult,
                    'n'  => 1,
                ];
            }

            if ($current !== null) {
                $segments[] = $current;
            }
        }

        return $segments;
    }

    /**
     * @throws NonUniqueResultException
     */
    #[Route(path: '/{judgehostid<\d+>}', name: 'jury_judgehost', methods: ['GET'])]
    #[ReleaseSessionLock]
    public function viewAction(Request $request, int $judgehostid): Response
    {
        /** @var Judgehost|null $judgehost */
        $judgehost = $this->em->createQueryBuilder()
            ->from(Judgehost::class, 'j')
            ->select('j')
            ->andWhere('j.judgehostid = :judgehostid')
            ->setParameter('judgehostid', $judgehostid)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$judgehost) {
            throw new NotFoundHttpException(sprintf('Judgehost with ID %d not found', $judgehostid));
        }

        $reltime = floor(Utils::difftime(Utils::now(), (float)$judgehost->getPolltime()));
        if ($reltime < $this->config->get('judgehost_warning')) {
            $statusIcon = 'ok';
            $status = 'OK';
        } elseif ($reltime < $this->config->get('judgehost_critical')) {
            $statusIcon = 'warn';
            $status = 'Warning';
        } else {
            $statusIcon = 'crit';
            $status = 'Critical';
        }

        /** @var Judging[] $judgings */
        $judgings = [];
        if ($contests = $this->dj->getCurrentContest()) {
            $judgings = $this->em->createQueryBuilder()
                ->from(Judging::class, 'j')
                ->select('j', 'r')
                ->leftJoin('j.rejudging', 'r')
                ->andWhere('j.contest IN (:contests)')
                ->andWhere(
                    'EXISTS (SELECT 1 FROM ' . JudgingRun::class . ' jr JOIN jr.judgetask jt'
                    . ' WHERE jr.judging = j AND jt.judgehost = :judgehost)'
                )
                ->setParameter('contests', $contests)
                ->setParameter('judgehost', $judgehost)
                ->orderBy('j.starttime', 'DESC')
                ->addOrderBy('j.judgingid', 'DESC')
                ->getQuery()
                ->getResult();
        }

        $data = [
            'judgehost' => $judgehost,
            'previousNext' => $this->getPreviousAndNextObjectIds(
                Judgehost::class,
                $judgehost->getJudgehostid(),
                'judgehostid',
                ['e.judgehostid' => 'ASC'],
            ),
            'status' => $status,
            'statusIcon' => $statusIcon,
            'judgings' => $judgings,
            'refresh' => [
                'after' => 15,
                'url' => $this->generateUrl('jury_judgehost', ['judgehostid' => $judgehost->getJudgehostid()]),
                'ajax' => true,
            ],
        ];
        if ($request->isXmlHttpRequest()) {
            return $this->render('jury/partials/judgehost_judgings.html.twig', $data);
        } else {
            return $this->render('jury/judgehost.html.twig', $data);
        }
    }

    /**
     * @throws DBALException
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    #[IsGranted('ROLE_ADMIN')]
    #[Route(path: '/{judgehostid<\d+>}/delete', name: 'jury_judgehost_delete')]
    public function deleteAction(Request $request, int $judgehostid): Response
    {
        /** @var Judgehost $judgehost */
        $judgehost = $this->em->createQueryBuilder()
            ->from(Judgehost::class, 'j')
            ->select('j')
            ->andWhere('j.judgehostid = :judgehostid')
            ->setParameter('judgehostid', $judgehostid)
            ->getQuery()
            ->getOneOrNullResult();

        return $this->deleteEntities($request, [$judgehost], $this->generateUrl('jury_judgehosts'));
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route(path: '/{judgehostid<\d+>}/enable', name: 'jury_judgehost_enable')]
    public function enableAction(RouterInterface $router, Request $request, int $judgehostid): RedirectResponse
    {
        /** @var Judgehost $judgehost */
        $judgehost = $this->em->getRepository(Judgehost::class)->find($judgehostid);
        $judgehost->setEnabled(true);
        $this->em->flush();
        $this->dj->auditlog('judgehost', (string)$judgehost->getJudgehostid(), 'marked enabled');
        return $this->redirectToLocalReferrer($router, $request, $this->generateUrl('jury_judgehosts'));
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route(path: '/{judgehostid<\d+>}/disable', name: 'jury_judgehost_disable')]
    public function disableAction(RouterInterface $router, Request $request, int $judgehostid): RedirectResponse
    {
        /** @var Judgehost $judgehost */
        $judgehost = $this->em->getRepository(Judgehost::class)->find($judgehostid);
        $judgehost->setEnabled(false);
        $this->em->flush();
        $this->dj->auditlog('judgehost', (string)$judgehost->getJudgehostid(), 'marked disabled');
        return $this->redirectToLocalReferrer($router, $request, $this->generateUrl('jury_judgehosts'));
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route(path: '/enable-all', name: 'jury_judgehost_enable_all', methods: ['POST'])]
    public function enableAllAction(): RedirectResponse
    {
        $this->em->createQuery('UPDATE App\Entity\Judgehost j set j.enabled = true')->execute();
        $this->dj->auditlog('judgehost', null, 'marked all enabled');
        return $this->redirectToRoute('jury_judgehosts');
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route(path: '/disable-all', name: 'jury_judgehost_disable_all', methods: ['POST'])]
    public function disableAllAction(): RedirectResponse
    {
        $this->em->createQuery('UPDATE App\Entity\Judgehost j set j.enabled = false')->execute();
        $this->dj->auditlog('judgehost', null, 'marked all disabled');
        return $this->redirectToRoute('jury_judgehosts');
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route(path: '/autohide', name: 'jury_judgehost_autohide', methods: ['POST'])]
    public function autohideInactive(): RedirectResponse
    {
        $now = Utils::now();
        $time_crit = $this->config->get('judgehost_critical');
        $critical_threshold = $now - $time_crit;

        $this->em->createQuery(
            'UPDATE App\Entity\Judgehost j set j.enabled = false, j.hidden = true WHERE j.polltime IS NULL OR j.polltime < :threshold')
            ->setParameter('threshold', $critical_threshold)
            ->execute();
        $this->dj->auditlog('judgehost', null, 'auto-hiding judgehosts');
        return $this->redirectToRoute('jury_judgehosts');
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route(path: '/edit/multiple', name: 'jury_judgehost_edit')]
    public function editMultipleAction(Request $request): Response
    {
        $querybuilder = $this->em->createQueryBuilder()
            ->from(Judgehost::class, 'j')
            ->select('j')
            ->orderBy('j.hostname');
        $includeHidden = $request->get('include_hidden', true);
        if (!$includeHidden) {
            $querybuilder->andWhere('j.hidden = 0');
        }
        $judgehosts   = ['judgehosts' => $querybuilder->getQuery()->getResult()];
        $form         = $this->createForm(JudgehostsType::class, $judgehosts);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->dj->auditlog('judgehosts', null, 'updated');
            $this->em->flush();

            return $this->redirectToRoute('jury_judgehosts');
        }

        return $this->render('jury/judgehosts_edit_multiple.html.twig', [
            'form' => $form,
        ]);
    }
}
