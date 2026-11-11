<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Contest;
use App\Entity\ContestProblem;
use App\Entity\ExternalRun;
use App\Entity\Judging;
use App\Entity\JudgingRun;
use App\Entity\Problem;
use App\Entity\RankCache;
use App\Entity\ScoreboardType;
use App\Entity\ScoreCache;
use App\Entity\Submission;
use App\Entity\Team;
use App\Entity\TeamAffiliation;
use App\Entity\TeamCategory;
use App\Utils\FreezeData;
use App\Utils\Scoreboard\Filter;
use App\Utils\Scoreboard\Scoreboard;
use App\Utils\Scoreboard\SingleTeamScoreboard;
use App\Utils\Utils;
use Doctrine\Common\Collections\Order;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ScoreboardService
{
    final public const SHOW_TEAM_ALWAYS = 0;
    final public const SHOW_TEAM_AFTER_LOGIN = 1;
    final public const SHOW_TEAM_AFTER_SUBMIT = 2;

    private const SCORE_CACHE_UPDATE_COLUMNS = [
        'submissions_restricted', 'pending_restricted', 'solvetime_restricted', 'runtime_restricted',
        'is_correct_restricted', 'score_restricted', 'submissions_public', 'pending_public',
        'solvetime_public', 'runtime_public', 'is_correct_public', 'score_public', 'is_first_to_solve',
    ];

    private const RANK_CACHE_UPDATE_COLUMNS = [
        'points_restricted', 'totaltime_restricted', 'totalruntime_restricted', 'score_restricted', 'sort_key_restricted',
        'points_public', 'totaltime_public', 'totalruntime_public', 'score_public', 'sort_key_public',
    ];

    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly DOMJudgeService $dj,
        protected readonly ConfigurationService $config,
        protected readonly LoggerInterface $logger,
    ) {}

    /**
     * Get scoreboard data based on the cached data in the scorecache table.
     *
     * @param Contest     $contest     The contest to get the scoreboard for.
     * @param bool        $jury        If true, the scoreboard will always be current.
     *                                 If false, frozen results will not be returned.
     * @param Filter|null $filter      Filter to use for the scoreboard.
     * @param bool        $visibleOnly Iff $jury is true, determines whether
     *                                 to show non-publicly visible teams.
     */
    public function getScoreboard(
        Contest $contest,
        bool $jury = false,
        ?Filter $filter = null,
        bool $visibleOnly = false,
        bool $forceUnfrozen = false,
    ): ?Scoreboard {
        $freezeData = new FreezeData($contest);

        // Don't leak information before start of contest.
        if (!$freezeData->started() && !$jury && !$forceUnfrozen) {
            return null;
        }
        $restricted = ($jury || $freezeData->showFinal(false) || $forceUnfrozen);

        $teams      = $this->getTeamsInOrder($contest, $jury && !$visibleOnly, $filter, $restricted);
        $problems   = $this->getProblems($contest);
        $categories = $this->getCategories($jury && !$visibleOnly);
        $scoreCache = $this->getScorecache($contest);
        $rankCache  = $this->getRankcache($contest);

        return new Scoreboard(
            $contest, $teams, $categories, $problems,
            $scoreCache, $rankCache, $freezeData, $jury || $forceUnfrozen,
            (bool)$this->config->get('score_in_seconds'),
        );
    }

    /**
     * Get scoreboard data for a single team based on the cached data in the
     * scorecache table.
     *
     * @param Contest $contest         The contest to get the scoreboard for.
     * @param string  $teamId          The ID of the team to get the scoreboard for.
     * @param bool    $showFtsInFreeze If false, the scoreboard will hide first
     *                                 to solve for submissions after contest freeze.
     */
    public function getTeamScoreboard(Contest $contest, string $teamId, bool $showFtsInFreeze = true): ?Scoreboard
    {
        $freezeData = new FreezeData($contest);

        $teams = $this->getTeamsInOrder($contest, true, new Filter([], [], [], [$teamId]), true);
        if (empty($teams)) {
            return null;
        }
        $team       = reset($teams);
        $problems   = $this->getProblems($contest);
        $rankCache  = $this->getRankcache($contest, $team);
        $scoreCache = $this->getScorecache($contest, $team);
        $teamRank   = $this->calculateTeamRank($contest, $team, $freezeData, true);

        return new SingleTeamScoreboard(
            $contest, $team, $teamRank, $problems,
            $rankCache, $scoreCache, $freezeData, $showFtsInFreeze,
            (bool)$this->config->get('score_in_seconds')
        );
    }

    /**
     * Calculate the rank for a single team based on the cache tables.
     *
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function calculateTeamRank(
        Contest $contest,
        Team $team,
        ?FreezeData $freezeData = null,
        bool $jury = false
    ): int {
        if ($freezeData === null) {
            $freezeData = new FreezeData($contest);
        }
        $restricted = ($jury || $freezeData->showFinal(false));
        $variant    = $restricted ? 'Restricted' : 'Public';
        $sortOrder  = $team->getSortOrder();

        $sortKey = $this->em->createQueryBuilder()
            ->from(RankCache::class, 'r')
            ->select('r.sortKey'.$variant)
            ->andWhere('r.contest = :contest')
            ->andWhere('r.team = :team')
            ->setParameter('contest', $contest)
            ->setParameter('team', $team)
            ->getQuery()
            ->getOneOrNullResult();

        if ($sortKey === null) {
            // '.' sorts before any digit, so this team will be ranked last (which may be actually rank 1 if no team
            // solved anything yet).
            $sortKey = ".";
        }

        $better = $this->em->createQueryBuilder()
            ->from(RankCache::class, 'r')
            ->join('r.team', 't')
            ->join('t.categories', 'tc', Join::WITH, 'BIT_AND(tc.types, :scoring) = :scoring')
            ->select('COUNT(t.teamid)')
            ->andWhere('r.sortKey'.$variant.' > :sortKey')
            ->andWhere('r.contest = :contest')
            ->andWhere('tc.sortorder = :sortorder')
            ->setParameter('scoring', TeamCategory::TYPE_SCORING)
            ->setParameter('sortKey', $sortKey)
            ->setParameter('contest', $contest)
            ->setParameter('sortorder', $sortOrder)
            ->getQuery()
            ->getSingleScalarResult();

        $rank = $better + 1;
        return $rank;
    }

    /**
     * Scoreboard calculation
     *
     * Given a contest, team and a problem (re)calculate the values for one
     * row in the scoreboard.
     *
     * Due to current transactions usage, this function MUST NOT do anything
     * inside a transaction.
     *
     * @param bool $updateRankCache If set to false, do not update the rankcache.
     * @param bool $refreshFirstToSolvePeers If set to false, do not recalculate the team that might
     *             have its first to solve status changed by this update.
     * @throws DBALException
     */
    public function calculateScoreRow(
        Contest $contest,
        Team    $team,
        Problem $problem,
        bool    $updateRankCache = true,
        bool    $refreshFirstToSolvePeers = true
    ): void {
        $this->logger->debug(
            "ScoreboardService::calculateScoreRow '%d' '%d' '%d'",
            [ $contest->getCid(), $team->getTeamid(), $problem->getProbid() ]
        );

        if (!$team->getScoringCategory()) {
            $this->logger->warning(
                "Team '%d' has no category, skipping",
                [ $team->getTeamid() ]
            );
            return;
        }

        // First acquire an advisory lock to prevent other calls to this
        // method from interfering with our update.
        $lockString = sprintf('domjudge.%d.%d.%d',
                              $contest->getCid(), $team->getTeamid(), $problem->getProbid());
        if ($this->em->getConnection()->fetchOne('SELECT GET_LOCK(:lock, 3)',
                                                    ['lock' => $lockString]) != 1) {
            throw new Exception(sprintf("ScoreboardService::calculateScoreRow failed to obtain lock '%s'",
                                         $lockString));
        }

        // Determine whether we will use external judgements instead of judgings.
        $useExternalJudgements = $contest->isExternalSourceUseJudgements();
        $compilePenalty = (bool)$this->config->get('compile_penalty');
        $verificationRequired = (bool)$this->config->get('verification_required');

        /** @var list<array{submittime: string, result: ?string, verified?: bool|int|string, score: ?string, max_runtime: string|float|null}> $submissions */
        $submissions = $this->buildSubmissionsQueryBuilder($contest)
            ->andWhere('s.team = :team')
            ->andWhere('s.problem = :problem')
            ->setParameter('team', $team)
            ->setParameter('problem', $problem)
            ->getQuery()
            ->getScalarResult();

        $cell = $this->evaluateCellSubmissions($contest, $problem, $submissions, $compilePenalty, $verificationRequired);
        $correctJury = $cell['is_correct_restricted'];
        $correctPubl = $cell['is_correct_public'];
        $mayBlockFirstToSolve = $cell['may_block_first_to_solve'];

        // See if this submission was the first to solve this problem.
        // Only relevant if it was correct in the first place.
        $firstToSolve = false;
        if ($correctJury) {
            $params = [
                'cid' => $contest->getCid(),
                'probid' => $problem->getProbid(),
                'teamSortOrder' => $team->getSortorder(),
                'submitTime' => $cell['abs_submittime'],
                'correctResult' => Judging::RESULT_CORRECT,
                'scoring' => TeamCategory::TYPE_SCORING,
            ];

            // Find out how many valid submissions were submitted earlier
            // that have a valid judging that is correct, or are awaiting judgement.
            // Only if there are 0 found, we are definitely the first to solve this problem.
            // To find relevant submissions/judgings:
            // - submission needs to be valid (not invalidated)
            // - a valid judging is present, but
            //   - either it's still ongoing (pending judgement, could be correct)
            //   - or already judged to be correct (if it is judged but not correct,
            //     it is not a first to solve)
            // - or the submission is still queued for judgement (judgehost is NULL).
            $verificationRequiredExtra = $verificationRequired ? 'OR j.verified = 0' : '';
            if ($useExternalJudgements) {
                $firstToSolve = 0 == $this->em->getConnection()->fetchOne('
                SELECT count(*) FROM submission s
                    LEFT JOIN external_judgement ej USING (submitid)
                    LEFT JOIN external_judgement ej2 ON ej2.submitid = s.submitid AND ej2.starttime > ej.starttime
                    LEFT JOIN team t USING(teamid)
                    # TODO: category type
                    LEFT JOIN team_category_team tcc USING (teamid)
                    LEFT JOIN team_category tc ON tc.categoryid = tcc.categoryid AND (tc.types & :scoring) = :scoring
                WHERE s.valid = 1 AND
                    (ej.result IS NULL OR ej.result = :correctResult '.
                    $verificationRequiredExtra.') AND
                    s.cid = :cid AND s.probid = :probid AND
                    tc.sortorder = :teamSortOrder AND
                    round(s.submittime,4) < :submitTime', $params);
            } else {
                $firstToSolve = 0 == $this->em->getConnection()->fetchOne('
                SELECT count(*) FROM submission s
                    LEFT JOIN judging j ON (s.submitid=j.submitid AND j.valid=1)
                    LEFT JOIN team t USING (teamid)
                    # TODO: category type
                    LEFT JOIN team_category_team tcc USING (teamid)
                    LEFT JOIN team_category tc ON tc.categoryid = tcc.categoryid AND (tc.types & :scoring) = :scoring
                WHERE s.valid = 1 AND
                    (j.judgingid IS NULL OR j.result IS NULL OR j.result = :correctResult '.
                    $verificationRequiredExtra.') AND
                    s.cid = :cid AND s.probid = :probid AND
                    tc.sortorder = :teamSortOrder AND
                    round(s.submittime,4) < :submitTime', $params);
            }
        }

        // Remember whether this cell used to count as solved (the rank cache has to be updated
        // when a solve is taken away) and whether it used to prevent other teams from being first to solve.
        $previous = $this->em->getConnection()->fetchAssociative(
            'SELECT is_correct_restricted OR is_correct_public AS was_correct, pending_restricted
             FROM scorecache WHERE cid = :cid AND teamid = :teamid AND probid = :probid',
            ['cid' => $contest->getCid(), 'teamid' => $team->getTeamid(), 'probid' => $problem->getProbid()]
        );
        $wasCorrect = $previous !== false && (bool)$previous['was_correct'];
        $hadBlockedFirstToSolve = $wasCorrect || ($previous !== false && $previous['pending_restricted'] > 0);

        $this->upsertCacheRows(
            'scorecache',
            ['cid', 'teamid', 'probid'],
            self::SCORE_CACHE_UPDATE_COLUMNS,
            [[
                $contest->getCid(),
                $team->getTeamid(),
                $problem->getProbid(),
                $cell['submissions_restricted'],
                $cell['pending_restricted'],
                $cell['solvetime_restricted'],
                $cell['runtime_restricted'],
                (int)$correctJury,
                $cell['score_restricted'],
                $cell['submissions_public'],
                $cell['pending_public'],
                $cell['solvetime_public'],
                $cell['runtime_public'],
                (int)$correctPubl,
                $cell['score_public'],
                (int)$firstToSolve,
            ]]
        );

        if ($this->em->getConnection()->fetchOne('SELECT RELEASE_LOCK(:lock)',
                                                    ['lock' => $lockString]) != 1) {
            throw new Exception('ScoreboardService::calculateScoreRow failed to release lock');
        }

        // If we found a new correct result (or any result for scoring problems), or lost a previously
        // correct one, update the rank cache too.
        if ($updateRankCache &&
            ($correctJury || $correctPubl || $wasCorrect || $problem->isScoringProblem())) {
            $this->updateRankCache($contest, $team);
        }

        // Whether another team is first to solve depends on the submissions of all teams, so
        // changing the state of this team can change the status of another team.
        if ($refreshFirstToSolvePeers && ($hadBlockedFirstToSolve || $mayBlockFirstToSolve)) {
            $this->refreshFirstToSolvePeers($contest, $team, $problem);
        }
    }

    /**
     * Recalculate the team that might be first to solve a problem, after the score of
     * another team for that problem has changed.
     *
     * Only the team(s) with the earliest solve can be first to solve, as every other
     * team has that solve before it. This is the only team that needs to be looked at.
     *
     * Due to current transactions usage, this function MUST NOT do anything
     * inside a transaction.
     */
    private function refreshFirstToSolvePeers(Contest $contest, Team $team, Problem $problem): void
    {
        $connection = $this->em->getConnection();

        $peers = $connection->fetchAllAssociative(
            'SELECT sc.teamid, sc.solvetime_restricted
             FROM scorecache sc
             JOIN team_category_team tcc ON tcc.teamid = sc.teamid
             JOIN team_category tc ON tc.categoryid = tcc.categoryid AND (tc.types & :scoring) = :scoring
             WHERE sc.cid = :cid AND sc.probid = :probid AND sc.teamid != :teamid
                 AND sc.is_correct_restricted = 1 AND tc.sortorder = :sortorder
             ORDER BY sc.solvetime_restricted
             LIMIT 25',
            [
                'cid' => $contest->getCid(),
                'probid' => $problem->getProbid(),
                'teamid' => $team->getTeamid(),
                'sortorder' => $team->getSortorder(),
                'scoring' => TeamCategory::TYPE_SCORING,
            ]
        );
        if (empty($peers)) {
            return;
        }

        // A team can only have influenced peers that solved the problem after its first submission,
        // including submissions that have been invalidated in the meantime.
        $firstSubmitTime = $connection->fetchOne(
            'SELECT MIN(submittime) FROM submission WHERE cid = :cid AND teamid = :teamid AND probid = :probid',
            ['cid' => $contest->getCid(), 'teamid' => $team->getTeamid(), 'probid' => $problem->getProbid()]
        );
        if ($firstSubmitTime === null || $firstSubmitTime === false) {
            return;
        }
        $firstSubmitContestTime = (int)floor($contest->getContestTime((float)$firstSubmitTime));

        $earliest = $peers[0]['solvetime_restricted'];
        if ($earliest < $firstSubmitContestTime) {
            return;
        }

        foreach ($peers as $peer) {
            if ($peer['solvetime_restricted'] != $earliest) {
                break;
            }
            $peerTeam = $this->em->getRepository(Team::class)->find($peer['teamid']);
            if ($peerTeam !== null) {
                $this->calculateScoreRow(
                    $contest, $peerTeam, $problem,
                    updateRankCache: false,
                    refreshFirstToSolvePeers: false
                );
            }
        }
    }

    /**
     * Update tables used for efficiently computing team ranks.
     *
     * Given a contest and team (re)calculate the time and solved problems for a team.
     *
     * Due to current transactions usage, this function MUST NOT do anything
     * inside a transaction.
     */
    public function updateRankCache(Contest $contest, Team $team): void
    {
        $this->logger->debug("ScoreboardService::updateRankCache '%d' '%d'",
                             [ $contest->getCid(), $team->getTeamid() ]);

        // First acquire an advisory lock to prevent other calls to this
        // method from interfering with our update.
        $lockString = sprintf('domjudge.%d.%d', $contest->getCid(), $team->getTeamid());
        if ($this->em->getConnection()->fetchOne('SELECT GET_LOCK(:lock, 3)',
                                                    ['lock' => $lockString]) != 1) {
            throw new Exception(sprintf("ScoreboardService::updateRankCache failed to obtain lock '%s'", $lockString));
        }

        // Fetch the score cache cells of the problems that are in the contest. This only needs a
        // few columns, so do not load entities for them.
        /** @var list<array{points: int|string, is_correct_restricted: bool|int|string, submissions_restricted: int|string, solvetime_restricted: float|int|string, runtime_restricted: int|string, score_restricted: float|string, is_correct_public: bool|int|string, submissions_public: int|string, solvetime_public: float|int|string, runtime_public: int|string, score_public: float|string}> $scoreCacheCells */
        $scoreCacheCells = $this->em->createQueryBuilder()
            ->from(ScoreCache::class, 's')
            ->join(ContestProblem::class, 'cp', Join::WITH, 'cp.contest = s.contest AND cp.problem = s.problem')
            ->select('cp.points AS points')
            ->addSelect('s.is_correct_restricted AS is_correct_restricted')
            ->addSelect('s.submissions_restricted AS submissions_restricted')
            ->addSelect('s.solvetime_restricted AS solvetime_restricted')
            ->addSelect('s.runtime_restricted AS runtime_restricted')
            ->addSelect('s.scoreRestricted AS score_restricted')
            ->addSelect('s.is_correct_public AS is_correct_public')
            ->addSelect('s.submissions_public AS submissions_public')
            ->addSelect('s.solvetime_public AS solvetime_public')
            ->addSelect('s.runtime_public AS runtime_public')
            ->addSelect('s.scorePublic AS score_public')
            ->andWhere('s.contest = :contest')
            ->andWhere('s.team = :team')
            ->setParameter('contest', $contest)
            ->setParameter('team', $team)
            ->getQuery()
            ->getScalarResult();

        $scoreIsInSeconds = (bool)$this->config->get('score_in_seconds');
        $this->upsertCacheRows(
            'rankcache',
            ['cid', 'teamid'],
            self::RANK_CACHE_UPDATE_COLUMNS,
            [$this->computeRankCacheRow($contest, $team, $scoreCacheCells, $scoreIsInSeconds)]
        );

        if ($this->em->getConnection()->fetchOne('SELECT RELEASE_LOCK(:lock)',
                                                    ['lock' => $lockString]) != 1) {
            throw new Exception('ScoreboardService::updateRankCache failed to release lock');
        }
    }

    public const SCALE = 9;

    // Converts integer or bcmath floats to a string that can be used as a key in a score cache.
    // The resulting key will be a string with 33 characters, 23 before the decimal dot and 9 after.
    // The keys are left-padded with zeros to ensure they have the same length and are lexicographically sortable.
    // If the sort order is descending, the keys are inverted by subtracting them from a large number.
    // Assumes that the input is a non-negative number, smaller than 10^23.
    //
    // Example:
    // input: 42
    // output: "00000000000000000000042.000000000"
    public static function convertToScoreKeyElement(int|string $value, Order $order = Order::Descending): string
    {
        // Ensure we have a fixed precision number with 9 decimals.
        $value = bcadd("$value", "0", scale: self::SCALE);

        $ALMOST_INFINITE = "99999999999999999999999";
        if (bccomp($value, $ALMOST_INFINITE, scale: self::SCALE) > 0) {
            throw new Exception("Value $value is too large to convert to a score key element.");
        }
        if (str_starts_with($value, '-')) {
            throw new Exception("No negative values allowed in score key element, got $value.");
        }

        // If ascending, we need to subtract it from a large high value.
        if ($order === Order::Ascending) {
            $value = bcsub($ALMOST_INFINITE, $value, scale: self::SCALE);
        }

        // Left pad it so it has always the same number of characters.
        return str_pad($value, 33, "0", STR_PAD_LEFT);
    }

    public static function getICPCScoreKey(int $numSolved, int $totalTime, int $timeOfLastSolved): string
    {
        $scoreKeyArray = [
            self::convertToScoreKeyElement($numSolved),
            self::convertToScoreKeyElement($totalTime, Order::Ascending),
            self::convertToScoreKeyElement($timeOfLastSolved, Order::Ascending),
        ];
        return implode(',', $scoreKeyArray);
    }

    public static function getRuntimeScoreKey(int $numSolved, int $totalRuntime): string
    {
        // When runtime is the tiebreaker, it takes the place of the penalty time: that is also
        // the only tiebreaker shown on the scoreboard, so teams with an equal runtime tie.
        $scoreKeyArray = [
            self::convertToScoreKeyElement($numSolved),
            self::convertToScoreKeyElement($totalRuntime, Order::Ascending),
        ];
        return implode(',', $scoreKeyArray);
    }

    public static function getScoringScoreKey(string $score): string
    {
        // For scoring problems, we only use the score as the key (for now).
        // We assume that the score is a valid bcmath number.
        $scoreKeyArray = [
            self::convertToScoreKeyElement($score, Order::Descending),
        ];
        return implode(',', $scoreKeyArray);
    }

    /**
     * Recalculate the rankCache of a contest, leaving the scoreCache alone.
     *
     * This is needed when a contest setting changes that only affects the sort key,
     * since the rank cache is otherwise only updated for teams whose score changes,
     * which would leave sort keys of different generations to be compared to each other.
     */
    public function refreshRankCache(Contest $contest): void
    {
        Utils::extendMaxExecutionTime(300);

        $this->dj->auditlog('contest', $contest->getExternalid(), 'refresh rank cache');

        /** @var Team[] $teams */
        $teams = $this->em->createQueryBuilder()
            ->from(Team::class, 't')
            ->innerJoin(RankCache::class, 'r', Join::WITH, 'r.team = t AND r.contest = :contest')
            ->select('t')
            ->setParameter('contest', $contest)
            ->orderBy('t.teamid')
            ->getQuery()
            ->getResult();

        foreach ($teams as $team) {
            $this->updateRankCache($contest, $team);
        }
    }

    /**
     * Recalculate the scoreCache and rankCache of a contest.
     *
     * $progressReporter (optional) should be a callable that takes a string.
     */
    public function refreshCache(Contest $contest, ?callable $progressReporter = null): void
    {
        Utils::extendMaxExecutionTime(300);

        $this->dj->auditlog('contest', $contest->getExternalid(), 'refresh scoreboard cache');

        if ($progressReporter === null) {
            $progressReporter = static function (int $progress, string $log, ?string $message = null): void {
                // no-op
            };
        }

        $queryBuilder = $this->em->createQueryBuilder()
            ->from(Team::class, 't')
            ->leftJoin('t.categories', 'tc')
            ->select('t, tc')
            ->distinct()
            ->orderBy('t.teamid');
        if (!$contest->isOpenToAllTeams()) {
            $queryBuilder
                ->leftJoin('t.contests', 'c')
                ->join('t.categories', 'cat')
                ->leftJoin('cat.contests', 'cc')
                ->andWhere('c.cid = :cid OR cc.cid = :cid')
                ->setParameter('cid', $contest->getCid());
        }
        /** @var Team[] $teams */
        $teams = $queryBuilder->getQuery()->getResult();
        /** @var ContestProblem[] $contestProblems */
        $contestProblems = $this->em->createQueryBuilder()
            ->from(ContestProblem::class, 'cp')
            ->innerJoin('cp.problem', 'p')
            ->select('cp, p')
            ->andWhere('cp.contest = :contest')
            ->setParameter('contest', $contest)
            ->orderBy('cp.shortname')
            ->getQuery()
            ->getResult();

        if (count($teams) == 0) {
            $progressReporter(100, '', 'No teams defined, doing nothing.');
            return;
        }
        if (count($contestProblems) == 0) {
            $progressReporter(100, '', 'No problems defined, doing nothing.');
            return;
        }

        $cid = $contest->getCid();
        $useExternalJudgements = $contest->isExternalSourceUseJudgements();
        $compilePenalty = (bool)$this->config->get('compile_penalty');
        $verificationRequired = (bool)$this->config->get('verification_required');
        $scoreIsInSeconds = (bool)$this->config->get('score_in_seconds');

        /** @var list<array{teamid: int|string, probid: int|string, submittime: string, result: ?string, verified?: bool|int|string, score: ?string, max_runtime: string|float|null}> $allSubmissions */
        $allSubmissions = $this->buildSubmissionsQueryBuilder($contest)
            ->addSelect('IDENTITY(s.team) AS teamid', 'IDENTITY(s.problem) AS probid')
            ->getQuery()
            ->getScalarResult();

        $submissionsByCell = [];
        foreach ($allSubmissions as $submission) {
            $submissionsByCell[(int)$submission['teamid']][(int)$submission['probid']][] = $submission;
        }

        $earliestFtsBlocker = [];
        if ($submissionsByCell) {
            $ftsSql = $useExternalJudgements
                ? 'SELECT s.probid, tc.sortorder, MIN(ROUND(s.submittime, 4)) AS min_submittime
                   FROM submission s
                   LEFT JOIN external_judgement ej ON s.submitid = ej.submitid AND ej.valid = 1
                   INNER JOIN team_category_team tcc USING (teamid)
                   INNER JOIN team_category tc ON tc.categoryid = tcc.categoryid AND (tc.types & :scoring) = :scoring
                   WHERE s.valid = 1 AND s.cid = :cid
                       AND (ej.extjudgementid IS NULL OR ej.result IS NULL OR ej.result = :correctResult)
                   GROUP BY s.probid, tc.sortorder'
                : 'SELECT s.probid, tc.sortorder, MIN(ROUND(s.submittime, 4)) AS min_submittime
                   FROM submission s
                   LEFT JOIN judging j ON s.submitid = j.submitid AND j.valid = 1
                   INNER JOIN team_category_team tcc USING (teamid)
                   INNER JOIN team_category tc ON tc.categoryid = tcc.categoryid AND (tc.types & :scoring) = :scoring
                   WHERE s.valid = 1 AND s.cid = :cid
                       AND (j.judgingid IS NULL OR j.result IS NULL OR j.result = :correctResult'
                       . ($verificationRequired ? ' OR j.verified = 0' : '') . ')
                   GROUP BY s.probid, tc.sortorder';
            $ftsRows = $this->em->getConnection()->fetchAllAssociative($ftsSql, [
                'cid' => $cid,
                'correctResult' => Judging::RESULT_CORRECT,
                'scoring' => TeamCategory::TYPE_SCORING,
            ]);
            foreach ($ftsRows as $row) {
                $earliestFtsBlocker[(int)$row['probid']][(int)$row['sortorder']] = (float)$row['min_submittime'];
            }
        }

        $first = true;
        $log = '';
        $scoreCacheRows = [];
        $rankCacheRows = [];

        foreach ($teams as $index => $team) {
            if (!$first) {
                $log .= ', ';
            }
            $first = false;
            $log .= $team->getExternalid();
            $progress = (int)round($index / count($teams) * 100);
            $progressReporter($progress, $log);

            $teamId = $team->getTeamid();
            $teamCells = [];
            if ($team->getScoringCategory()) {
                $teamSortOrder = $team->getSortorder();
                foreach ($contestProblems as $cp) {
                    $problem = $cp->getProblem();
                    $probId = $problem->getProbid();
                    $cell = $this->evaluateCellSubmissions(
                        $contest,
                        $problem,
                        $submissionsByCell[$teamId][$probId] ?? [],
                        $compilePenalty,
                        $verificationRequired
                    );
                    $firstToSolve = $cell['is_correct_restricted']
                        && (!isset($earliestFtsBlocker[$probId][$teamSortOrder])
                            || $cell['abs_submittime'] <= $earliestFtsBlocker[$probId][$teamSortOrder]);

                    $scoreCacheRows[] = [
                        $cid,
                        $teamId,
                        $probId,
                        $cell['submissions_restricted'],
                        $cell['pending_restricted'],
                        $cell['solvetime_restricted'],
                        $cell['runtime_restricted'],
                        (int)$cell['is_correct_restricted'],
                        $cell['score_restricted'],
                        $cell['submissions_public'],
                        $cell['pending_public'],
                        $cell['solvetime_public'],
                        $cell['runtime_public'],
                        (int)$cell['is_correct_public'],
                        $cell['score_public'],
                        (int)$firstToSolve,
                    ];
                    $cell['points'] = $cp->getPoints();
                    $teamCells[] = $cell;
                }
            } else {
                $this->logger->warning("Team '%d' has no category, skipping", [$teamId]);
            }

            $rankCacheRows[] = $this->computeRankCacheRow($contest, $team, $teamCells, $scoreIsInSeconds);
        }

        $this->upsertCacheRows('scorecache', ['cid', 'teamid', 'probid'], self::SCORE_CACHE_UPDATE_COLUMNS, $scoreCacheRows);
        $this->upsertCacheRows('rankcache', ['cid', 'teamid'], self::RANK_CACHE_UPDATE_COLUMNS, $rankCacheRows);

        // Drop all teams and problems that do not exist in the contest.
        $problemIds = array_map(static fn(ContestProblem $cp) => $cp->getProblem()->getProbid(), $contestProblems);
        $teamIds = array_map(static fn(Team $team) => $team->getTeamid(), $teams);

        $params = [
            'cid' => $cid,
            'problemIds' => $problemIds,
        ];
        $types  = [
            'problemIds' => ArrayParameterType::INTEGER,
            'teamIds' => ArrayParameterType::INTEGER,
        ];
        $this->em->getConnection()->executeQuery(
            'DELETE FROM scorecache WHERE cid = :cid AND probid NOT IN (:problemIds)',
            $params, $types);

        $params = [
            'cid' => $cid,
            'teamIds' => $teamIds,
        ];
        $this->em->getConnection()->executeQuery(
            'DELETE FROM scorecache WHERE cid = :cid AND teamid NOT IN (:teamIds)',
            $params, $types);
        $this->em->getConnection()->executeQuery(
            'DELETE FROM rankcache WHERE cid = :cid AND teamid NOT IN (:teamIds)',
            $params, $types);

        $progressReporter(100, '');
    }

    /**
     * Initialize the scoreboard filter for the given request.
     */
    public function initializeScoreboardFilter(Request $request, ?Response $response): Filter
    {
        $scoreFilter = [];
        if ($this->dj->getCookie('domjudge_scorefilter')) {
            $scoreFilter = Utils::jsonDecode((string)$this->dj->getCookie('domjudge_scorefilter'));
        }

        if ($request->query->has('clear')) {
            $scoreFilter = [];
        }

        if ($request->query->has('filter')) {
            $scoreFilter = [];
            foreach (['affiliations', 'countries', 'categories'] as $type) {
                if ($request->query->has($type)) {
                    $scoreFilter[$type] = $request->query->all($type);
                }
            }
        }

        $this->dj->setCookie(
            'domjudge_scorefilter',
            Utils::jsonEncode($scoreFilter),
            0, null, '', false, false, $response
        );

        return new Filter(
            $scoreFilter['affiliations'] ?? [],
            $scoreFilter['countries'] ?? [],
            $scoreFilter['categories'] ?? [],
            $scoreFilter['teams'] ?? []
        );
    }

    /**
     * Get a list of affiliation names grouped on category name.
     *
     * @return array<array<string, array<array{id: string, name: string}>>>
     */
    public function getGroupedAffiliations(Contest $contest): array
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(TeamCategory::class, 'cat')
            ->select('cat', 't', 'affil')
            ->leftJoin('cat.teams', 't')
            ->leftJoin('t.affiliation', 'affil')
            ->andWhere('cat.visible = 1')
            ->andWhere('BIT_AND(cat.types, :scoring) = :scoring')
            ->orderBy('cat.name')
            ->addOrderBy('affil.name')
            ->addOrderBy('t.name')
            ->setParameter('scoring', TeamCategory::TYPE_SCORING);

        if (!$contest->isOpenToAllTeams()) {
            $queryBuilder
                ->leftJoin('t.contests', 'c')
                ->leftJoin('cat.contests', 'cc')
                ->andWhere('c = :contest OR cc = :contest')
                ->setParameter('contest', $contest);
        }

        /** @var TeamCategory[] $categories */
        $categories = $queryBuilder->getQuery()->getResult();

        $groupedAffiliations = [];
        foreach ($categories as $category) {
            $affiliations = [];
            /** @var Team $team */
            foreach ($category->getTeams() as $team) {
                if ($teamaffil = $team->getAffiliation()) {
                    $affiliations[$teamaffil->getName()] = [
                        'id'   => $teamaffil->getExternalid(),
                        'name' => $teamaffil->getName(),
                        'country' => $teamaffil->getCountry(),
                        'color' => $category->getColor(),
                    ];
                }
            }

            if (empty($affiliations)) {
                /** @var Team $team */
                foreach ($category->getTeams() as $team) {
                    $affiliations[$team->getEffectiveName()] = [
                        'id' => -1,
                        'name' => $team->getEffectiveName()];
                }
            }
            if (!empty($affiliations)) {
                $groupedAffiliations[$category->getName()] = array_values($affiliations);
            }
        }

        return array_chunk($groupedAffiliations, 3, true);
    }

    /**
     * Get values to display in the scoreboard filter.
     *
     * @return array{affiliations: string[], countries: string[], categories: string[]}
     */
    public function getFilterValues(Contest $contest, bool $jury): array
    {
        $filters = [
            'affiliations' => [],
            'countries'    => [],
            'categories'   => [],
        ];
        $showFlags        = $this->config->get('show_flags');
        $showAffiliations = $this->config->get('show_affiliations');

        $queryBuilder = $this->em->createQueryBuilder()
            ->from(TeamCategory::class, 'c')
            ->select('c');
        if (!$jury) {
            $queryBuilder->andWhere('c.visible = 1');
        }

        /** @var TeamCategory[] $categories */
        $categories = $queryBuilder->getQuery()->getResult();
        foreach ($categories as $category) {
            $filters['categories'][$category->getExternalid()] = $category->getName();
        }

        // Show only affiliations / countries with visible teams.
        if (empty($categories) || !$showAffiliations) {
            $filters['affiliations'] = [];
        } else {
            $queryBuilder = $this->em->createQueryBuilder()
                ->from(TeamAffiliation::class, 'a')
                ->select('a')
                ->join('a.teams', 't')
                ->join('t.categories', 'tc')
                ->andWhere('tc.categoryid IN (:categories)')
                ->setParameter('categories', $categories);
            if (!$contest->isOpenToAllTeams()) {
                $queryBuilder
                    ->leftJoin('t.contests', 'c')
                    ->join('t.categories', 'cat')
                    ->leftJoin('cat.contests', 'cc')
                    ->andWhere('c = :contest OR cc = :contest')
                    ->setParameter('contest', $contest);
            }

            /** @var TeamAffiliation[] $affiliations */
            $affiliations = $queryBuilder->getQuery()->getResult();
            foreach ($affiliations as $affiliation) {
                $filters['affiliations'][$affiliation->getExternalid()] = $affiliation->getName();
                if ($showFlags && $affiliation->getCountry() !== null) {
                    $filters['countries'][] = $affiliation->getCountry();
                }
            }
        }

        $filters['countries'] = array_unique($filters['countries']);
        sort($filters['countries']);
        asort($filters['affiliations'], SORT_FLAG_CASE);

        return $filters;
    }

    /**
     * Get the scoreboard Twig data for a given contest.
     *
     * @return array{refresh?: array{after: int, url: string, ajax: bool}, static: bool, contest?: Contest,
     *               scoreFilter?: Filter, scoreboard: Scoreboard, filterValues: array<string, string[]>,
     *               groupedAffiliations: null|array<array<string, array<array{id: string, name: string}>>>,
     *               showFlags: int, showAffiliationLogos: bool, showAffiliations: int, showPending: int,
     *               showTeamSubmissions: int, scoreInSeconds: bool, maxWidth: int, jury?: bool,
     *               public?: bool, ajax?: bool}
     */
    public function getScoreboardTwigData(
        ?Request $request,
        ?Response $response,
        string $refreshUrl,
        bool $jury,
        bool $public,
        bool $static,
        ?Contest $contest = null,
        ?Scoreboard $scoreboard = null,
        bool $forceUnfrozen = false,
    ): array {
        $data = [
            'refresh' => [
                'after' => 30,
                'url' => $refreshUrl,
                'ajax' => true,
             ],
             'static' => $static,
        ];
        if ($static && $contest && ($forceUnfrozen || $contest->getFreezeData()->showFinal())) {
            unset($data['refresh']);
            $data['refreshstop'] = true;
        }

        if ($contest) {
            if ($request && $response) {
                $scoreFilter = $this->initializeScoreboardFilter($request, $response);
            } else {
                $scoreFilter = null;
            }
            if ($scoreboard === null) {
                $scoreboard = $this->getScoreboard(
                    contest: $contest,
                    jury: $jury,
                    filter: $scoreFilter,
                    forceUnfrozen: $forceUnfrozen
                );
            }

            if ($forceUnfrozen) {
                $scoreboard->getFreezeData()
                    ->setForceValue(FreezeData::KEY_SHOW_FROZEN, false)
                    ->setForceValue(FreezeData::KEY_SHOW_FINAL, true)
                    ->setForceValue(FreezeData::KEY_SHOW_FINAL_JURY, true)
                    ->setForceValue(FreezeData::KEY_FINALIZED, true);

                if (!$contest->getFinalizetime()) {
                    $contest->setFinalizetime(Utils::now());
                }
            }

            $data['contest']              = $contest;
            $data['scoreFilter']          = $scoreFilter;
            $data['scoreboard']           = $scoreboard;
            $data['filterValues']         = $this->getFilterValues($contest, $jury);
            $data['groupedAffiliations']  = empty($scoreboard) ? $this->getGroupedAffiliations($contest) : null;
            $data['showFlags']            = $this->config->get('show_flags');
            $data['showAffiliationLogos'] = $this->config->get('show_affiliation_logos');
            $data['showAffiliations']     = $this->config->get('show_affiliations');
            $data['showPending']          = $this->config->get('show_pending');
            $data['showTeamSubmissions']  = $this->config->get('show_teams_submissions');
            $data['scoreInSeconds']       = $this->config->get('score_in_seconds');
            $data['maxWidth']             = $this->config->get('team_column_width');
        }

        if ($request && $request->isXmlHttpRequest()) {
            $data['jury']   = $jury;
            $data['public'] = $public;
            $data['ajax']   = true;
        }

        return $data;
    }

    /**
     * Get the teams to display on the scoreboard, returns them in order.
     * @return Team[]
     */
    protected function getTeamsInOrder(Contest $contest, bool $jury = false, ?Filter $filter = null, bool $restricted = false): array
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(Team::class, 't', 't.teamid')
            // Join on categories twice: once to determine the sort order (tc) and once to get all categories the team belongs to (tcc)
            ->innerJoin('t.categories', 'tc', Join::WITH, 'BIT_AND(tc.types, :scoring) = :scoring')
            ->innerJoin('t.categories', 'tcc')
            ->leftJoin(RankCache::class, 'r', Join::WITH, 'r.team = t AND r.contest = :rcid')
            ->leftJoin('t.affiliation', 'ta')
            ->select('t, tcc, ta', 'COALESCE(t.display_name, t.name) AS HIDDEN effectivename')
            ->andWhere('t.enabled = 1')
            ->setParameter('rcid', $contest->getCid())
            ->setParameter('scoring', TeamCategory::TYPE_SCORING);

        if (!$contest->isOpenToAllTeams()) {
            $queryBuilder
                ->leftJoin('t.contests', 'c')
                ->join('t.categories', 'cat')
                ->leftJoin('cat.contests', 'cc')
                ->andWhere('c.cid = :cid OR cc.cid = :cid')
                ->setParameter('cid', $contest->getCid());
        }

        $show_filter = $this->config->get('show_teams_on_scoreboard');
        if (!$jury) {
            $queryBuilder->andWhere('tc.visible = 1');
            $queryBuilder->andWhere('tcc.visible = 1');
            if ($show_filter === self::SHOW_TEAM_AFTER_LOGIN) {
                $queryBuilder
                    ->join('t.users', 'u', Join::WITH, 'u.last_login IS NOT NULL OR u.last_api_login IS NOT NULL');
            } elseif ($show_filter === self::SHOW_TEAM_AFTER_SUBMIT) {
                $queryBuilder
                    ->join('t.submissions', 's', Join::WITH, 's.contest = :cid')
                    ->setParameter('cid', $contest->getCid());
            }
        }

        if ($filter) {
            if ($filter->affiliations) {
                $queryBuilder
                    ->andWhere('ta.externalid IN (:affiliations)')
                    ->setParameter('affiliations', $filter->affiliations);
            }

            if ($filter->categories) {
                // Use a new join, since we need both the other two category joins for other logic already
                $queryBuilder
                    ->innerJoin('t.categories', 'tccc')
                    ->andWhere('tccc.externalid IN (:categories)')
                    ->setParameter('categories', $filter->categories);
                if (!$jury) {
                    $queryBuilder->andWhere('tccc.visible = 1');
                }
            }

            if ($filter->countries) {
                $queryBuilder
                    ->andWhere('ta.country IN (:countries)')
                    ->setParameter('countries', $filter->countries);
            }

            if ($filter->teams) {
                $queryBuilder
                    ->andWhere('t.externalid IN (:teams)')
                    ->setParameter('teams', $filter->teams);
            }
        }

        $ret = $queryBuilder
            ->addOrderBy('tc.sortorder')
            ->addOrderBy('r.sortKey' . ($restricted ? 'Restricted' : 'Public'), 'DESC')
            ->addOrderBy('effectivename')
            ->getQuery()->getResult();
        return $ret;
    }

    /**
     * Get the problems to display on the scoreboard.
     *
     * Note that this will return only a partial object for optimization purposes.
     *
     * @return ContestProblem[]
     */
    protected function getProblems(Contest $contest): array
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(ContestProblem::class, 'cp')
            ->select('cp, p')
            ->innerJoin('cp.problem', 'p')
            ->andWhere('cp.allowSubmit = 1')
            ->andWhere('cp.contest = :contest')
            ->setParameter('contest', $contest)
            ->orderBy('cp.shortname');

        /** @var ContestProblem[] $contestProblems */
        $contestProblems = $queryBuilder->getQuery()->getResult();
        $contestProblemsIndexed = [];
        foreach ($contestProblems as $cp) {
            /** @var Problem|int $p */
            $p = $cp->getProblem();
            // Doctrine has a bug with eagerly loaded second level hydration
            // when the object is already loaded. In that case it might happen
            // that the problem of a contest problem is its ID instead of the
            // whole object. If this happes, load the whole problem. This
            // should not do any additional database queries, since the problem
            // has already been loaded.
            // See https://github.com/doctrine/orm/pull/7145 for the Doctrine issue.
            if (is_numeric($p)) {
                $p = $this->em->getRepository(Problem::class)->find($p);
                $cp->setProblem($p);
            }
            $contestProblemsIndexed[$p->getProbid()] = $cp;
        }
        return $contestProblemsIndexed;
    }

    /**
     * Get the categories to display on the scoreboard.
     * @return TeamCategory[]
     */
    protected function getCategories(bool $jury): array
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(TeamCategory::class, 'cat', 'cat.categoryid')
            ->select('cat')
            ->orderBy('cat.sortorder')
            ->addOrderBy('cat.name')
            ->addOrderBy('cat.categoryid');

        if (!$jury) {
            $queryBuilder->andWhere('cat.visible = 1');
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Get the scorecache used to calculate the scoreboard.
     * @return ScoreCache[]
     */
    protected function getScorecache(Contest $contest, ?Team $team = null): array
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(ScoreCache::class, 's')
            ->select('s')
            ->andWhere('s.contest = :contest')
            ->setParameter('contest', $contest);

        if ($team) {
            $queryBuilder
                ->andWhere('s.team = :team')
                ->setParameter('team', $team);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Get the rank cache for the given team.
     * @throws NonUniqueResultException
     * @return RankCache[]
     */
    protected function getRankcache(Contest $contest, ?Team $team = null): array
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(RankCache::class, 'r')
            ->select('r')
            ->andWhere('r.contest = :contest')
            ->setParameter('contest', $contest);

        if ($team !== null) {
            $queryBuilder
                ->andWhere('r.team = :team')
                ->setParameter('team', $team);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Build the base scalar query for valid in-time submissions of a contest.
     */
    private function buildSubmissionsQueryBuilder(Contest $contest): QueryBuilder
    {
        // Note the clause 's.submittime < c.endtime': this is used to
        // filter out TOO-LATE submissions from pending, but it also means
        // that these will not count as solved. Correct submissions with
        // submittime after contest end should never happen, unless one
        // resets the contest time after successful judging.
        //
        // We only select the few columns we need instead of loading entities, as judgings
        // can carry large compile output. The maximum runtime is only needed for correct judgings.
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(Submission::class, 's')
            ->select('s.submittime AS submittime')
            ->join('s.contest', 'c')
            ->andWhere('s.contest = :contest')
            ->andWhere('s.valid = 1')
            ->andWhere('s.submittime < c.endtime')
            ->setParameter('contest', $contest)
            ->setParameter('correctResult', Judging::RESULT_CORRECT)
            ->orderBy('s.submittime')
            ->addOrderBy('s.submitid');

        if ($contest->isExternalSourceUseJudgements()) {
            $queryBuilder
                ->addSelect('ej.result AS result', 'ej.score AS score')
                ->addSelect('(SELECT MAX(er.runtime) FROM ' . ExternalRun::class . ' er
                              WHERE er.external_judgement = ej AND ej.result = :correctResult) AS max_runtime')
                ->leftJoin('s.external_judgements', 'ej', Join::WITH, 'ej.valid = 1');
        } else {
            $queryBuilder
                ->addSelect('j.result AS result', 'j.verified AS verified', 'j.score AS score')
                ->addSelect('(SELECT MAX(jr.runtime) FROM ' . JudgingRun::class . ' jr
                              WHERE jr.judging = j AND j.result = :correctResult) AS max_runtime')
                ->leftJoin('s.judgings', 'j', Join::WITH, 'j.valid = 1');
        }

        return $queryBuilder;
    }

    /**
     * @param list<array{submittime: string, result: ?string, verified?: bool|int|string, score: ?string, max_runtime: string|float|null}> $submissions
     * @return array{
     *     submissions_restricted: int,
     *     pending_restricted: int,
     *     solvetime_restricted: int,
     *     runtime_restricted: int,
     *     is_correct_restricted: bool,
     *     score_restricted: string,
     *     submissions_public: int,
     *     pending_public: int,
     *     solvetime_public: int,
     *     runtime_public: int,
     *     is_correct_public: bool,
     *     score_public: string,
     *     abs_submittime: float,
     *     may_block_first_to_solve: bool,
     * }
     */
    private function evaluateCellSubmissions(
        Contest $contest,
        Problem $problem,
        array $submissions,
        bool $compilePenalty,
        bool $verificationRequired,
    ): array {
        $useExternalJudgements = $contest->isExternalSourceUseJudgements();
        $isScoringProblem = $problem->isScoringProblem();
        $contestStartTime = (float)$contest->getStarttime(false);
        $freezeTime = $contest->getFreezetime();

        $submissionsJury = $pendingJury = $timeJury = 0;
        $submissionsPubl = $pendingPubl = $timePubl = 0;
        $correctJury = false;
        $correctPubl = false;
        $runtimeJury = PHP_INT_MAX;
        $runtimePubl = PHP_INT_MAX;
        $pointsJury = '0';
        $pointsPubl = '0';
        $absSubmitTime = 0.0;
        $mayBlockFirstToSolve = false;

        foreach ($submissions as $submission) {
            $result = $submission['result'];
            $isAfterFreeze = $freezeTime !== null && (float)$submission['submittime'] >= $freezeTime;

            if ($isScoringProblem) {
                $score = $submission['score'];
                if ($score !== null) {
                    if (bccomp($pointsJury, $score, scale: self::SCALE) < 0) {
                        $pointsJury = $score;
                    }
                    if (!$isAfterFreeze && bccomp($pointsPubl, $score, scale: self::SCALE) < 0) {
                        $pointsPubl = $score;
                    }
                }
            }

            // STEP 1: runtime improvements should be possible for all correct submissions
            if ($result == Judging::RESULT_CORRECT) {
                $runtime = (int)floor(1000 * (float)$submission['max_runtime']);
                $runtimeJury = min($runtimeJury, $runtime);
                if (!$isAfterFreeze) {
                    $runtimePubl = min($runtimePubl, $runtime);
                }
            }

            if (!$isScoringProblem && $correctPubl) {
                continue;
            }

            // STEP 2: check if this submission has a publicly visible judging result
            if (empty($result) || (!$useExternalJudgements && $verificationRequired && empty($submission['verified']))) {
                if (!$correctJury) {
                    $pendingJury++;
                }
                $pendingPubl++;
                $mayBlockFirstToSolve = true;
                continue;
            }

            $countSubmission = $compilePenalty || $result != Judging::RESULT_COMPILER_ERROR;
            if (($isScoringProblem || !$correctJury) && $countSubmission) {
                $submissionsJury++;
            }
            if ($isAfterFreeze) {
                $pendingPubl++;
            } elseif ($countSubmission) {
                $submissionsPubl++;
            }

            if (!$isScoringProblem && $correctJury) {
                continue;
            }

            // STEP 3: determine time of first correct submission
            $absSubmitTime = max((float)$submission['submittime'], $contestStartTime);
            $submitTime = $contest->getContestTime($absSubmitTime);

            if ($result == Judging::RESULT_CORRECT) {
                $mayBlockFirstToSolve = true;
                $correctJury = true;
                $timeJury = $submitTime;
                if (!$isAfterFreeze) {
                    $correctPubl = true;
                    $timePubl = $submitTime;
                }
            }
        }

        return [
            'submissions_restricted' => $submissionsJury,
            'pending_restricted' => $pendingJury,
            'solvetime_restricted' => (int)$timeJury,
            'runtime_restricted' => $runtimeJury === PHP_INT_MAX ? 0 : $runtimeJury,
            'is_correct_restricted' => $correctJury,
            'score_restricted' => $pointsJury,
            'submissions_public' => $submissionsPubl,
            'pending_public' => $pendingPubl,
            'solvetime_public' => (int)$timePubl,
            'runtime_public' => $runtimePubl === PHP_INT_MAX ? 0 : $runtimePubl,
            'is_correct_public' => $correctPubl,
            'score_public' => $pointsPubl,
            'abs_submittime' => $absSubmitTime,
            'may_block_first_to_solve' => $mayBlockFirstToSolve,
        ];
    }

    /**
     * @param list<array{
     *     points: int|string,
     *     submissions_restricted: int|string,
     *     solvetime_restricted: float|int|string,
     *     runtime_restricted: int|string,
     *     is_correct_restricted: bool|int|string,
     *     score_restricted: float|string,
     *     submissions_public: int|string,
     *     solvetime_public: float|int|string,
     *     runtime_public: int|string,
     *     is_correct_public: bool|int|string,
     *     score_public: float|string,
     * }> $cells
     * @return list<int|string>
     */
    private function computeRankCacheRow(Contest $contest, Team $team, array $cells, bool $scoreIsInSeconds): array
    {
        $variants = ['restricted', 'public'];
        $numPoints = [];
        $totalTime = [];
        $totalRuntime = [];
        $timeOfLastCorrect = [];
        $score = [];
        foreach ($variants as $variant) {
            $numPoints[$variant] = 0;
            $totalTime[$variant] = $team->getPenalty();
            $totalRuntime[$variant] = 0;
            $timeOfLastCorrect[$variant] = 0;
            $score[$variant] = '0';
        }

        $penaltyTime = $contest->getPenaltyTime();
        $isScoringContest = $contest->getScoreboardType() === ScoreboardType::SCORE;

        foreach ($cells as $cell) {
            foreach ($variants as $variant) {
                $cellScore = (string)$cell['score_' . $variant];
                $isCorrect = (bool)$cell['is_correct_' . $variant];
                if ($isScoringContest) {
                    $score[$variant] = bcadd($score[$variant], $cellScore, self::SCALE);
                }
                if ($isCorrect) {
                    $penalty = Utils::calcPenaltyTime(
                        true,
                        (int)$cell['submissions_' . $variant],
                        $penaltyTime,
                        $scoreIsInSeconds
                    );
                    $numPoints[$variant] += (int)$cell['points'];
                    $solveTime = Utils::scoretime((float)$cell['solvetime_' . $variant], $scoreIsInSeconds);
                    $timeOfLastCorrect[$variant] = max($timeOfLastCorrect[$variant], $solveTime);
                    $totalTime[$variant] += $solveTime + $penalty;
                    $totalRuntime[$variant] += (int)$cell['runtime_' . $variant];
                    if (!$isScoringContest) {
                        $score[$variant] = bcadd($score[$variant], $cellScore, self::SCALE);
                    }
                }
            }
        }

        $scoreKey = [];
        foreach ($variants as $variant) {
            if ($contest->getScoreboardType() === ScoreboardType::PASS_FAIL) {
                $scoreKey[$variant] = $contest->getRuntimeAsScoreTiebreaker()
                    ? self::getRuntimeScoreKey($numPoints[$variant], $totalRuntime[$variant])
                    : self::getICPCScoreKey($numPoints[$variant], $totalTime[$variant], $timeOfLastCorrect[$variant]);
            } else {
                $scoreKey[$variant] = self::getScoringScoreKey($score[$variant]);
            }
        }

        return [
            $contest->getCid(),
            $team->getTeamid(),
            $numPoints['restricted'],
            $totalTime['restricted'],
            $totalRuntime['restricted'],
            $score['restricted'],
            $scoreKey['restricted'],
            $numPoints['public'],
            $totalTime['public'],
            $totalRuntime['public'],
            $score['public'],
            $scoreKey['public'],
        ];
    }

    /**
     * Upsert cache rows in chunks without rewriting unchanged columns via DELETE+INSERT.
     *
     * @param list<string>           $keyColumns
     * @param list<string>           $updateColumns
     * @param list<list<int|string>> $rows
     */
    private function upsertCacheRows(string $table, array $keyColumns, array $updateColumns, array $rows): void
    {
        if (!$rows) {
            return;
        }
        $columns = array_merge($keyColumns, $updateColumns);
        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $updates = implode(', ', array_map(
            static fn(string $column) => "$column = VALUES($column)",
            $updateColumns
        ));
        $sqlPrefix = sprintf('INSERT INTO %s (%s) VALUES ', $table, implode(', ', $columns));
        $sqlSuffix = ' ON DUPLICATE KEY UPDATE ' . $updates;

        $connection = $this->em->getConnection();
        foreach (array_chunk($rows, 1000) as $batch) {
            $placeholders = implode(', ', array_fill(0, count($batch), $rowPlaceholder));
            $connection->executeStatement(
                $sqlPrefix . $placeholders . $sqlSuffix,
                array_merge(...$batch)
            );
        }
    }
}
