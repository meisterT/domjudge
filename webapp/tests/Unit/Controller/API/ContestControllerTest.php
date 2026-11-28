<?php declare(strict_types=1);

namespace App\Tests\Unit\Controller\API;

use App\DataFixtures\Test\DemoPreStartContestFixture;
use App\Entity\Contest;
use App\Entity\Event;
use App\Tests\StatementHook\StatementHookMiddleware;
use App\Utils\CcsApiVersion;
use App\Utils\Utils;
use Doctrine\ORM\EntityManagerInterface;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ContestControllerTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        StatementHookMiddleware::reset();

        parent::tearDown();
    }
    protected ?string $apiEndpoint = 'contests';

    protected array $expectedObjects = [
        'demo' => [
            'formal_name'                => 'Demo contest',
            'penalty_time'               => '0:20:00',
            // 'start_time'                 => '2021-01-01T11:00:00+00:00',
            // 'end_time'                   => '2024-01-01T16:00:00+00:00',
            'duration'                   => '5:00:00.000',
            'scoreboard_freeze_duration' => '1:00:00.000',
            'id'                         => 'demo',
            'name'                       => 'Demo contest',
            'shortname'                  => 'demo',
            'banner'                     => null,
        ],
    ];

    protected array $expectedAbsent = ['4242', 'nonexistent'];

    protected ?string $objectClassForExternalId = Contest::class;

    /**
     * Test that a contest that is activated but not yet started is visible in the list action for a team user.
     */
    public function testListShowsActivatedButNotStartedContest(): void
    {
        $this->loadFixture(DemoPreStartContestFixture::class);

        $url = $this->helperGetEndpointURL($this->apiEndpoint);
        // Use 'demo' user which has team role
        $objects = $this->verifyApiJsonResponse('GET', $url, 200, 'demo');

        self::assertIsArray($objects);
        self::assertNotEmpty($objects, 'Contest list should not be empty');

        // Find the demo contest in the response
        $foundContest = null;
        foreach ($objects as $contest) {
            if ($contest['shortname'] === 'demo') {
                $foundContest = $contest;
                break;
            }
        }

        self::assertNotNull($foundContest, 'Demo contest should be visible after activation even before start');
        self::assertSame('Demo contest', $foundContest['formal_name']);
    }

    /**
     * Test that a contest that is activated but not yet started is visible in the single action for a team user.
     */
    public function testSingleShowsActivatedButNotStartedContest(): void
    {
        $this->loadFixture(DemoPreStartContestFixture::class);

        $contestId = $this->resolveEntityId(Contest::class, '1');
        $url = $this->helperGetEndpointURL($this->apiEndpoint, $contestId);
        // Use 'demo' user which has team role
        $contest = $this->verifyApiJsonResponse('GET', $url, 200, 'demo');

        self::assertIsArray($contest);
        self::assertSame('Demo contest', $contest['formal_name']);
        self::assertSame('demo', $contest['shortname']);
    }

    public function testEventFeedOnlyContainsEventsOfConfiguredVersion(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $contest = $em->getRepository(Contest::class)->findOneBy(['shortname' => 'demo']);
        $events = [];
        foreach ([CcsApiVersion::Format_2020_03, CcsApiVersion::Format_2026_01] as $version) {
            $event = (new Event())
                ->setContest($contest)
                ->setEventtime(Utils::now())
                ->setEndpointtype('teams')
                ->setEndpointid('team-' . $version->value)
                ->setAction('create')
                ->setContent(['id' => 'team-' . $version->value])
                ->setVersion($version);
            $em->persist($event);
            $em->flush();
            $events[$version->value] = $event->getEventid();
        }

        $url = '/contests/' . $this->getDemoContestId() . '/event-feed?stream=false&types=teams';
        $feed = $this->verifyApiResponse('GET', $url, 200, 'admin', attachment: true);
        $tokens = array_map(
            fn(string $line) => json_decode($line, true)['token'],
            array_filter(explode("\n", $feed))
        );
        self::assertContains((string)$events['2026-01'], $tokens);
        self::assertNotContains((string)$events['2020-03'], $tokens);

        $this->verifyApiResponse('GET', $url . '&since_token=' . $events['2026-01'], 200, 'admin', attachment: true);
        $this->verifyApiResponse('GET', $url . '&since_token=' . $events['2020-03'], 400, 'admin');
    }

    #[DataProvider('provideProblemLimitsInStrictEventFeed')]
    public function testProblemLimitsInStrictEventFeed(string $ccsApiVersion, bool $expectLimits): void
    {
        $this->withChangedConfiguration('ccs_api_version', $ccsApiVersion, function () use ($expectLimits): void {
            $url = '/contests/' . $this->getDemoContestId() . '/event-feed?stream=false&types=problems&strict=true';
            $feed = $this->verifyApiResponse('GET', $url, 200, 'admin', attachment: true);
            $problems = array_map(
                fn(string $line) => json_decode($line, true)['data'],
                array_filter(explode("\n", $feed))
            );
            self::assertNotEmpty($problems);
            foreach ($problems as $problem) {
                foreach (['memory_limit', 'output_limit', 'code_limit'] as $field) {
                    self::assertSame($expectLimits, array_key_exists($field, $problem), $field);
                }
            }
        });
    }

    public static function provideProblemLimitsInStrictEventFeed(): Generator
    {
        yield ['2023-06', false];
        yield ['2026-01', true];
    }

    public function testEventFeedFiltersOtherContestAcrossBatches(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $demoContest = $em->getRepository(Contest::class)->findOneBy(['shortname' => 'demo']);

        $otherContest = (new Contest())
            ->setExternalid('other')
            ->setName('Other contest')
            ->setShortname('other')
            ->setStarttimeString('2021-07-17 16:09:00 Europe/Amsterdam')
            ->setEndtimeString('2021-07-17 16:11:00 Europe/Amsterdam');
        $em->persist($otherContest);
        $em->flush();

        $connection = $em->getConnection();
        $nextId = (int)$connection->fetchOne('SELECT COALESCE(MAX(eventid), 0) FROM event');
        $now = sprintf('%.3f', Utils::now());
        $version = CcsApiVersion::Format_2026_01->getContentVersion()->value;

        // Insert more than EVENT_FEED_BATCH_SIZE (500) events for each contest, interleaved,
        // with explicit contiguous IDs so rolled-back AUTO_INCREMENT drift does not create a gap.
        $demoIds = [];
        $otherIds = [];
        for ($i = 1; $i <= 550; $i++) {
            $otherEventId = ++$nextId;
            $connection->insert('event', [
                'eventid' => $otherEventId,
                'eventtime' => $now,
                'cid' => $otherContest->getCid(),
                'endpointtype' => 'clarifications',
                'endpointid' => 'other-clar-' . $i,
                'action' => 'create',
                'content' => json_encode(['id' => 'other-clar-' . $i], JSON_THROW_ON_ERROR),
                'version' => $version,
            ]);
            $otherIds[] = (string)$otherEventId;

            $demoEventId = ++$nextId;
            $connection->insert('event', [
                'eventid' => $demoEventId,
                'eventtime' => $now,
                'cid' => $demoContest->getCid(),
                'endpointtype' => 'clarifications',
                'endpointid' => 'demo-clar-' . $i,
                'action' => 'create',
                'content' => json_encode(['id' => 'demo-clar-' . $i], JSON_THROW_ON_ERROR),
                'version' => $version,
            ]);
            $demoIds[] = (string)$demoEventId;
        }

        $url = '/contests/' . $this->getDemoContestId() . '/event-feed?stream=false&types=clarifications';
        $feed = $this->verifyApiResponse('GET', $url, 200, 'admin', attachment: true);
        $events = array_values(array_map(
            static fn(string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_filter(explode("\n", $feed))
        ));

        $tokens = array_column($events, 'token');
        $sortedTokens = $tokens;
        sort($sortedTokens, SORT_NUMERIC);
        self::assertSame($sortedTokens, $tokens);

        self::assertSame($demoIds, array_values(array_intersect($demoIds, $tokens)));
        self::assertSame([], array_values(array_intersect($otherIds, $tokens)));

        // Resuming from a demo token mid-way across batches returns only the remaining demo events.
        $sinceToken = $demoIds[299];
        $tailFeed = $this->verifyApiResponse('GET', $url . '&since_token=' . $sinceToken, 200, 'admin', attachment: true);
        $tailTokens = array_map(
            static fn(string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR)['token'],
            array_values(array_filter(explode("\n", $tailFeed)))
        );
        self::assertSame(array_slice($demoIds, 300), $tailTokens);

        // Using a token belonging to the other contest is rejected with 400.
        $this->verifyApiResponse('GET', $url . '&since_token=' . $otherIds[0], 400, 'admin');
    }

    public function testEventFeedSkipsMissingEventIdGap(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $demoContest = $em->getRepository(Contest::class)->findOneBy(['shortname' => 'demo']);
        $connection = $em->getConnection();

        $baseId = (int)$connection->fetchOne('SELECT COALESCE(MAX(eventid), 0) FROM event');
        $now = sprintf('%.3f', Utils::now());
        $version = CcsApiVersion::Format_2026_01->getContentVersion()->value;

        // Leave a gap at $baseId + 2 and $baseId + 3 between two events of the contest.
        foreach ([$baseId + 1, $baseId + 4] as $eventId) {
            $connection->insert('event', [
                'eventid' => $eventId,
                'eventtime' => $now,
                'cid' => $demoContest->getCid(),
                'endpointtype' => 'clarifications',
                'endpointid' => 'gap-clar-' . $eventId,
                'action' => 'create',
                'content' => json_encode(['id' => 'gap-clar-' . $eventId], JSON_THROW_ON_ERROR),
                'version' => $version,
            ]);
        }

        $url = '/contests/' . $this->getDemoContestId() . '/event-feed?stream=false&types=clarifications&since_token=' . ($baseId + 1);
        $start = microtime(true);
        $feed = $this->verifyApiResponse('GET', $url, 200, 'admin', attachment: true);
        $elapsed = microtime(true) - $start;
        $tokens = array_map(
            static fn(string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR)['token'],
            array_values(array_filter(explode("\n", $feed)))
        );

        self::assertSame([(string)($baseId + 4)], $tokens);
        self::assertGreaterThanOrEqual(0.9, $elapsed);
    }

    public function testEventFeedEmitsEventCommittedDuringGapWait(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $demoContest = $em->getRepository(Contest::class)->findOneBy(['shortname' => 'demo']);
        $connection = $em->getConnection();

        $baseId = (int)$connection->fetchOne('SELECT COALESCE(MAX(eventid), 0) FROM event');
        $now = sprintf('%.3f', Utils::now());
        $version = CcsApiVersion::Format_2026_01->getContentVersion()->value;
        $row = static fn(int $id): array => [
            'eventid' => $id,
            'eventtime' => $now,
            'cid' => $demoContest->getCid(),
            'endpointtype' => 'clarifications',
            'endpointid' => 'wait-fill-' . $id,
            'action' => 'create',
            'content' => json_encode(['id' => 'wait-fill-' . $id], JSON_THROW_ON_ERROR),
            'version' => $version,
        ];

        $connection->insert('event', $row($baseId + 1));
        $connection->insert('event', $row($baseId + 3));

        $polledAgain = false;
        StatementHookMiddleware::once(
            'SELECT COUNT(*) FROM event WHERE eventid BETWEEN ',
            static function () use ($connection, $row, $baseId, &$polledAgain): void {
                $connection->insert('event', $row($baseId + 2));
                StatementHookMiddleware::once(
                    'SELECT COUNT(*) FROM event WHERE eventid BETWEEN ',
                    static function () use (&$polledAgain): void {
                        $polledAgain = true;
                    }
                );
            }
        );

        $url = '/contests/' . $this->getDemoContestId() . '/event-feed?stream=false&types=clarifications&since_token=' . ($baseId + 1);
        $feed = $this->verifyApiResponse('GET', $url, 200, 'admin', attachment: true);
        $tokens = array_map(
            static fn(string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR)['token'],
            array_values(array_filter(explode("\n", $feed)))
        );

        self::assertSame([(string)($baseId + 2), (string)($baseId + 3)], $tokens);
        self::assertFalse($polledAgain);
    }

    public function testEventFeedEmitsEventCommittedBetweenBatchAndGapCheck(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $demoContest = $em->getRepository(Contest::class)->findOneBy(['shortname' => 'demo']);
        $connection = $em->getConnection();

        $baseId = (int)$connection->fetchOne('SELECT COALESCE(MAX(eventid), 0) FROM event');
        $now = sprintf('%.3f', Utils::now());
        $version = CcsApiVersion::Format_2026_01->getContentVersion()->value;
        $row = static fn(int $id): array => [
            'eventid' => $id,
            'eventtime' => $now,
            'cid' => $demoContest->getCid(),
            'endpointtype' => 'clarifications',
            'endpointid' => 'race-' . $id,
            'action' => 'create',
            'content' => json_encode(['id' => 'race-' . $id], JSON_THROW_ON_ERROR),
            'version' => $version,
        ];

        $connection->insert('event', $row($baseId + 1));
        $connection->insert('event', $row($baseId + 3));

        // An earlier transaction holding $baseId + 2 commits right after the batch query.
        StatementHookMiddleware::once(
            'SELECT COUNT(*) FROM event WHERE eventid > ',
            static fn() => $connection->insert('event', $row($baseId + 2))
        );

        $url = '/contests/' . $this->getDemoContestId() . '/event-feed?stream=false&types=clarifications&since_token=' . ($baseId + 1);
        $feed = $this->verifyApiResponse('GET', $url, 200, 'admin', attachment: true);
        $tokens = array_map(
            static fn(string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR)['token'],
            array_values(array_filter(explode("\n", $feed)))
        );

        self::assertSame([(string)($baseId + 2), (string)($baseId + 3)], $tokens);
    }

    public function testEventFeedWaitsOnceAcrossOldGaps(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $demoContest = $em->getRepository(Contest::class)->findOneBy(['shortname' => 'demo']);

        $otherContest = (new Contest())
            ->setExternalid('other-gaps')
            ->setName('Other contest with gaps')
            ->setShortname('other-gaps')
            ->setStarttimeString('2021-07-17 16:09:00 Europe/Amsterdam')
            ->setEndtimeString('2021-07-17 16:11:00 Europe/Amsterdam');
        $em->persist($otherContest);
        $em->flush();

        $connection = $em->getConnection();
        $baseId = (int)$connection->fetchOne('SELECT COALESCE(MAX(eventid), 0) FROM event');
        $now = sprintf('%.3f', Utils::now());
        $version = CcsApiVersion::Format_2026_01->getContentVersion()->value;

        $anchorId = $baseId + 1;
        $connection->insert('event', [
            'eventid' => $anchorId,
            'eventtime' => $now,
            'cid' => $demoContest->getCid(),
            'endpointtype' => 'clarifications',
            'endpointid' => 'anchor',
            'action' => 'create',
            'content' => json_encode(['id' => 'anchor'], JSON_THROW_ON_ERROR),
            'version' => $version,
        ]);

        // Create 20 separate missing-ID gaps across both contests; waiting 1s per gap
        // would take ~20s, whereas waiting once up to MAX(eventid) takes ~1s.
        $expectedDemoTokens = [];
        $nextId = $anchorId + 2; // Leave a gap right after $anchorId as well.
        for ($i = 1; $i <= 20; $i++) {
            $isDemo = ($i % 2 === 0);
            $connection->insert('event', [
                'eventid' => $nextId,
                'eventtime' => $now,
                'cid' => $isDemo ? $demoContest->getCid() : $otherContest->getCid(),
                'endpointtype' => 'clarifications',
                'endpointid' => 'multi-gap-' . $i,
                'action' => 'create',
                'content' => json_encode(['id' => 'multi-gap-' . $i], JSON_THROW_ON_ERROR),
                'version' => $version,
            ]);
            if ($isDemo) {
                $expectedDemoTokens[] = (string)$nextId;
            }
            $nextId += 3; // Leave a 2-ID gap before the next event.
        }

        $url = '/contests/' . $this->getDemoContestId() . '/event-feed?stream=false&types=clarifications&since_token=' . $anchorId;
        $start = microtime(true);
        $feed = $this->verifyApiResponse('GET', $url, 200, 'admin', attachment: true);
        $elapsed = microtime(true) - $start;

        $tokens = array_map(
            static fn(string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR)['token'],
            array_values(array_filter(explode("\n", $feed)))
        );

        self::assertSame($expectedDemoTokens, $tokens);
        self::assertLessThan(5.0, $elapsed);
    }

    public function testAdminCanModifyInactiveContestProblemset(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $future = (new Contest())
            ->setExternalid('future-inactive')
            ->setName('Future inactive contest')
            ->setShortname('future-inactive')
            ->setActivatetimeString('2099-01-01 10:00:00 UTC')
            ->setStarttimeString('2099-01-01 12:00:00 UTC')
            ->setEndtimeString('2099-01-01 17:00:00 UTC');
        $em->persist($future);
        $em->flush();

        $tmpFile = tempnam(sys_get_temp_dir(), 'problemset');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'Hello problemset');
        try {
            $uploaded = new UploadedFile($tmpFile, 'contest.txt', 'text/plain', null, true);
            $this->verifyApiResponse('POST', '/contests/future-inactive/problemset', 204, 'admin', files: ['problemset' => $uploaded]);
            $this->verifyApiResponse('DELETE', '/contests/future-inactive/problemset', 204, 'admin');
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testPutContestUpdatesMetadataAndPreservesOmittedFields(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $demo = $em->getRepository(Contest::class)->findOneBy(['shortname' => 'demo']);
        $originalActivate = $demo->getActivatetimeString();
        $demo->setWarningMessage('Keep this warning');
        $em->flush();

        $payload = [
            'id' => 'demo',
            'name' => 'Updated Demo Contest',
            'start_time' => '2026-11-26T12:00:00+00:00',
            'duration' => '4:00:00',
            'scoreboard_freeze_duration' => '0:30:00',
            'penalty_time' => 15,
        ];
        $updated = $this->verifyApiJsonResponse('PUT', '/contests/demo', 200, 'admin', $payload);

        self::assertSame('demo', $updated['id']);
        self::assertSame('Updated Demo Contest', $updated['name']);
        self::assertSame('4:00:00.000', $updated['duration']);
        self::assertSame('0:30:00.000', $updated['scoreboard_freeze_duration']);

        $em->clear();
        $reloaded = $em->getRepository(Contest::class)->findOneBy(['externalid' => 'demo']);
        self::assertSame('Updated Demo Contest', $reloaded->getName());
        self::assertSame(15, $reloaded->getPenaltyTime());
        self::assertSame($originalActivate, $reloaded->getActivatetimeString());
        self::assertSame('Keep this warning', $reloaded->getWarningMessage());

        $updateEvent = $em->getRepository(Event::class)->findOneBy([
            'contest' => $reloaded,
            'endpointtype' => 'contests',
            'action' => 'update',
        ]);
        self::assertNotNull($updateEvent);
    }

    public function testPutContestAcceptsYamlBody(): void
    {
        $adminPasswordFile = sprintf(
            '%s/%s',
            static::getContainer()->getParameter('domjudge.etcdir'),
            'initial_admin_password.secret'
        );
        $yaml = "id: demo\nname: YAML Updated Contest\nstart_time: 2026-11-26T12:00:00+00:00\nduration: 5:00:00\n";
        $this->client->request('PUT', '/api/contests/demo', [], [], [
            'CONTENT_TYPE' => 'application/x-yaml',
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => trim(file_get_contents($adminPasswordFile)),
        ], $yaml);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('YAML Updated Contest', $data['name']);
    }

    public function testPutContestGuardsAndValidation(): void
    {
        $validPayload = [
            'id' => 'demo',
            'name' => 'Demo contest',
            'start_time' => '2026-11-26T12:00:00+00:00',
            'duration' => '5:00:00',
        ];

        // Requires admin role.
        $this->verifyApiResponse('PUT', '/contests/demo', 403, 'demo', $validPayload);

        // Incompatible scoreboard type rejected when pass-fail problems exist.
        $this->verifyApiResponse('PUT', '/contests/demo', 400, 'admin', array_merge($validPayload, [
            'scoreboard_type' => 'score',
        ]));

        // Validation error rolls back without persisting.
        $this->verifyApiResponse('PUT', '/contests/demo', 400, 'admin', array_merge($validPayload, [
            'name' => '',
        ]));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $demo = $em->getRepository(Contest::class)->findOneBy(['externalid' => 'demo']);
        self::assertSame('Demo contest', $demo->getName());

        // Locked contest rejected with 403.
        $demo->setIsLocked(true);
        $em->flush();
        $this->verifyApiResponse('PUT', '/contests/demo', 403, 'admin', $validPayload);
    }
}
