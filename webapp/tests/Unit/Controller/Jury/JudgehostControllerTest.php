<?php declare(strict_types=1);

namespace App\Tests\Unit\Controller\Jury;

use App\DataFixtures\Test\ExtraJudgehostFixture;
use App\DataFixtures\Test\SampleSubmissionsFixture;
use App\Entity\Judgehost;
use App\Entity\JudgeTask;
use App\Entity\Judging;
use App\Entity\JudgingRun;
use App\Entity\Submission;
use App\Entity\Testcase;
use App\Entity\TestcaseContent;
use App\Utils\Utils;
use Doctrine\ORM\EntityManagerInterface;

class JudgehostControllerTest extends JuryControllerTestCase
{
    protected static string  $identifyingEditAttribute = 'hostname';
    protected static ?string $defaultEditEntityName    = 'example-judgehost1';
    protected static string  $baseUrl                  = '/jury/judgehosts';
    protected static array   $exampleEntries           = ['example-judgehost1'];
    protected static array   $deleteEntities           = ['example-judgehost1','example-judgehost2'];
    protected static string  $deleteEntityIdentifier   = 'hostname';
    protected static array   $deleteFixtures           = [ExtraJudgehostFixture::class];
    protected static string  $getIDFunc                = 'getJudgehostid';
    protected static string  $className                = Judgehost::class;
    protected static array   $DOM_elements             = ['h1' => ['Judgehosts']];
    protected static string  $add                      = '';
    protected static string  $edit                     = '';

    public function testTimelineIsCollapsedByDefault(): void
    {
        $this->roles = ['admin'];
        $this->logOut();
        $this->logIn();

        $this->verifyPageResponse('GET', '/jury/judgehosts', 200);
        $crawler = $this->getCurrentCrawler();
        $details = $crawler->filter('details#judgehost-timeline-details');
        self::assertCount(1, $details);
        self::assertNull($details->attr('open'));
    }

    public function testTimelineDataEndpoint(): void
    {
        $this->roles = ['jury'];
        $this->logOut();
        $this->logIn();

        $this->client->request('GET', '/jury/judgehosts/timeline-data');
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('now', $payload);
        self::assertSame('1h', $payload['window']);
        self::assertEqualsWithDelta(3600.0, $payload['now'] - $payload['window_start'], 1.0);
        self::assertFalse($payload['truncated']);
        self::assertArrayHasKey('max_runs', $payload);
        self::assertArrayHasKey('judgehosts', $payload);
        self::assertArrayHasKey('contests', $payload);
        self::assertArrayHasKey('problems', $payload);
        self::assertArrayHasKey('data', $payload);
        self::assertIsArray($payload['judgehosts']);
        self::assertIsArray($payload['data']);

        $this->client->request('GET', '/jury/judgehosts/timeline-data?window=invalid');
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    private function createJudgingRun(
        EntityManagerInterface $em,
        Judgehost $judgehost,
        Submission $submission,
        Judging $judging,
        int $rank,
        float $startTime,
        float $endTime,
        ?string $result = 'correct',
    ): void {
        $content = (new TestcaseContent())
            ->setInput("in-$rank")
            ->setOutput("out-$rank");
        $em->persist($content);

        $testcase = (new Testcase())
            ->setContent($content)
            ->setProblem($submission->getProblem())
            ->setRank($rank)
            ->setDescription("testcase $rank")
            ->setMd5sumInput(md5("in-$rank"))
            ->setMd5sumOutput(md5("out-$rank"));
        $em->persist($testcase);

        $task = (new JudgeTask())
            ->setJudgehost($judgehost)
            ->setPriority(JudgeTask::PRIORITY_DEFAULT)
            ->setJobId($judging->getJudgingid())
            ->setSubmission($submission)
            ->setStarttime($startTime);
        $em->persist($task);

        $run = (new JudgingRun())
            ->setJudging($judging)
            ->setTestcase($testcase)
            ->setJudgeTask($task)
            ->setStarttime($startTime)
            ->setEndtime($endTime)
            ->setRuntime(max(0.0, $endTime - $startTime));
        if ($result !== null) {
            $run->setRunresult($result);
        }
        $em->persist($run);
    }

    public function testTimelineCoalescesContiguousRunsAndFiltersWindow(): void
    {
        $this->loadFixture(SampleSubmissionsFixture::class);
        $this->roles = ['jury'];
        $this->logOut();
        $this->logIn();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Judgehost $judgehost */
        $judgehost = $em->getRepository(Judgehost::class)->findOneBy(['hostname' => 'example-judgehost1']);
        /** @var Submission $submission */
        $submission = $em->getRepository(Submission::class)->findOneBy([], ['submitid' => 'DESC']);
        $problem = $submission->getProblem();

        $hiddenJudgehost = (new Judgehost())
            ->setHostname('hidden-judgehost')
            ->setEnabled(true)
            ->setHidden(true);
        $em->persist($hiddenJudgehost);

        $now = (float)Utils::now();
        // Set overall judging result to 'timelimit' to verify the segment reports its own
        // first non-correct runresult ('wrong-answer') rather than the overall judging result.
        $judging = (new Judging())
            ->setContest($submission->getContest())
            ->setSubmission($submission)
            ->setStarttime($now - 101.0)
            ->setResult('timelimit');
        $em->persist($judging);
        $em->flush();

        // Runs 1-3 are contiguous (gaps <= 2s) and coalesce into one segment with result 'wrong-answer'.
        // Run 4 is separated by 17s (> 2s) and stays separate.
        // Run 5 is 2 hours old: excluded from default 1h window, included in 6h window.
        // Run 6 is on a hidden judgehost and must be excluded completely.
        $this->createJudgingRun($em, $judgehost, $submission, $judging, 100, $now - 100.0, $now - 99.0, 'correct');
        $this->createJudgingRun($em, $judgehost, $submission, $judging, 101, $now - 98.5, $now - 97.5, 'wrong-answer');
        $this->createJudgingRun($em, $judgehost, $submission, $judging, 102, $now - 97.2, $now - 97.0, null);
        $this->createJudgingRun($em, $judgehost, $submission, $judging, 103, $now - 80.0, $now - 79.0, 'correct');
        $this->createJudgingRun($em, $judgehost, $submission, $judging, 104, $now - 7200.0, $now - 7199.0, 'correct');
        $this->createJudgingRun($em, $hiddenJudgehost, $submission, $judging, 105, $now - 50.0, $now - 49.0, 'correct');
        $em->flush();

        // Default 1h window: 4 visible recent runs coalesced into 2 segments; hidden host excluded.
        $this->client->request('GET', '/jury/judgehosts/timeline-data');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $payload1h = json_decode((string)$this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $payload1h['data']);
        self::assertSame(3, $payload1h['data'][0]['n']);
        self::assertEqualsWithDelta($now - 100.0, $payload1h['data'][0]['st'], 0.01);
        self::assertEqualsWithDelta($now - 97.0, $payload1h['data'][0]['et'], 0.01);
        self::assertSame('wrong-answer', $payload1h['data'][0]['r']);
        self::assertSame(1, $payload1h['data'][1]['n']);
        self::assertSame('correct', $payload1h['data'][1]['r']);

        $cidKey = (string)$submission->getContest()->getCid();
        self::assertArrayHasKey($cidKey, $payload1h['contests']);
        self::assertSame($submission->getContest()->getShortname(), $payload1h['contests'][$cidKey]['shortname']);
        self::assertSame($submission->getContest()->getExternalid(), $payload1h['contests'][$cidKey]['externalid']);
        self::assertArrayHasKey((string)$problem->getProbid(), $payload1h['problems']);

        // 6h window: includes the 2-hour-old run as well (3 segments total).
        $this->client->request('GET', '/jury/judgehosts/timeline-data?window=6h');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $payload6h = json_decode((string)$this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('6h', $payload6h['window']);
        self::assertCount(3, $payload6h['data']);
    }
}
