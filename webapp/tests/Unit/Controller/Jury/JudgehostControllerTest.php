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
        self::assertArrayHasKey('twenty_four_hours_ago', $payload);
        self::assertArrayHasKey('judgehosts', $payload);
        self::assertArrayHasKey('data', $payload);
        self::assertIsArray($payload['judgehosts']);
        self::assertIsArray($payload['data']);
    }

    private function createJudgingRun(
        EntityManagerInterface $em,
        Judgehost $judgehost,
        Submission $submission,
        Judging $judging,
        int $rank,
        float $startTime,
        float $endTime,
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
            ->setRuntime(max(0.0, $endTime - $startTime))
            ->setRunresult('correct');
        $em->persist($run);
    }

    public function testJudgehostLoadAggregationAcrossWindows(): void
    {
        $this->loadFixture(SampleSubmissionsFixture::class);
        $this->roles = ['admin'];
        $this->logOut();
        $this->logIn();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Judgehost $judgehost */
        $judgehost = $em->getRepository(Judgehost::class)->findOneBy(['hostname' => 'example-judgehost1']);
        /** @var Submission $submission */
        $submission = $em->getRepository(Submission::class)->findOneBy([], ['submitid' => 'DESC']);

        $now = (float)Utils::now();
        $judging = (new Judging())
            ->setContest($submission->getContest())
            ->setSubmission($submission)
            ->setStarttime($now - 20000.0)
            ->setEndtime($now - 10.0)
            ->setResult('correct');
        $em->persist($judging);
        $em->flush();

        // 1) 150s run inside 5m window -> 150s in all 4 windows.
        // 2) 120s run straddling 5m boundary [now-360, now-240] -> 60s in 5m, 120s in 15m/1h/5h.
        // 3) 600s run inside 1h window [now-2000, now-1400] -> 0s in 5m/15m, 600s in 1h/5h.
        // 4) 300s run older than 5h [now-20000, now-19700] -> 0s in all windows.
        // Expected loads:
        //   5m:  (150 + 60) / 300        = 0.70
        //   15m: (150 + 120) / 900       = 0.30
        //   1h:  (150 + 120 + 600) / 3600 = 0.24
        //   5h:  (150 + 120 + 600) / 18000 = 0.05
        $this->createJudgingRun($em, $judgehost, $submission, $judging, 200, $now - 160.0, $now - 10.0);
        $this->createJudgingRun($em, $judgehost, $submission, $judging, 201, $now - 360.0, $now - 240.0);
        $this->createJudgingRun($em, $judgehost, $submission, $judging, 202, $now - 2000.0, $now - 1400.0);
        $this->createJudgingRun($em, $judgehost, $submission, $judging, 203, $now - 20000.0, $now - 19700.0);
        $em->flush();

        $this->verifyPageResponse('GET', '/jury/judgehosts', 200);
        $loadCells = $this->getCurrentCrawler()->filter('td[title="estimated load (5m/15m/1h/5h)"]');
        self::assertGreaterThanOrEqual(1, $loadCells->count());
        self::assertSame('0.70 / 0.30 / 0.24 / 0.05', trim($loadCells->first()->text()));
    }
}
