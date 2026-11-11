<?php declare(strict_types=1);

namespace App\Tests\Unit\Controller\Jury;

use PHPUnit\Framework\Attributes\DataProvider;
use App\DataFixtures\Test\SampleSubmissionsFixture;
use App\Entity\Contest;
use App\Entity\Judging;
use App\Entity\JudgingRun;
use App\Entity\JudgingRunOutput;
use App\Entity\JudgingRunPass;
use App\Entity\JudgingRunPassOutput;
use App\Entity\Problem;
use App\Entity\Submission;
use App\Entity\SubmissionSource;
use App\Entity\Team;
use App\Service\SubmissionService;
use App\Tests\Unit\BaseTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Generator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class SubmissionControllerTest extends BaseTestCase
{
    protected array         $roles   = ['jury'];
    protected static string $baseURL = '/jury/contests/demo/submissions';

    /**
     * Test that the basic building blocks of the index page are there.
     */
    public function testIndexBasic(): void
    {
        $this->verifyPageResponse('GET', static::$baseURL, 200);
    }

    /**
     * Test the filtered views do throw server errors.
     */
    #[DataProvider('provideViews')]
    public function testIndexViewFilter(string $filter, array $fixtures): void
    {
        $this->loadFixtures($fixtures);
        $this->verifyPageResponse('GET', static::$baseURL . '?view=' . $filter, 200);
    }

    public static function provideViews(): Generator
    {
        foreach ([[], [SampleSubmissionsFixture::class]] as $fixtures) {
            foreach (['all', 'unjudged', 'unverified'] as $view) {
                yield [$view, $fixtures];
            }
        }
    }

    public function testFilterFormSameSite(): void
    {
        $this->submitResultFilter([]);
        self::assertSame(['correct'], json_decode($this->filterCookie() ?? 'null', true)['result'] ?? null);
    }

    public function testFilterFormRejectsCrossSite(): void
    {
        $this->submitResultFilter(['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertNull($this->filterCookie());
    }

    private function filterCookie(): ?string
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'domjudge_submissionsfilter') {
                return $cookie->getValue();
            }
        }
        return null;
    }

    /**
     * @param array<string, string> $server
     */
    private function submitResultFilter(array $server): void
    {
        $this->verifyPageResponse('GET', static::$baseURL, 200);
        $form = $this->getCurrentCrawler()->selectButton('Apply filters')->form();
        $this->client->submit($form, ['submissions_filter[result]' => ['correct']], $server);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function addSubmission(): Submission
    {
        $em = $this->em();

        $message = null;
        return self::getContainer()->get(SubmissionService::class)->submitSolution(
            $em->getRepository(Team::class)->findOneBy(['name' => 'DOMjudge']),
            null,
            $em->getRepository(Problem::class)->findOneBy(['externalid' => 'hello']),
            $em->getRepository(Contest::class)->findOneBy(['shortname' => 'demo']),
            'c',
            [new UploadedFile(__FILE__, 'foo.c', null, null, true)],
            SubmissionSource::UNKNOWN, null, null, null, null, null, $message
        );
    }

    /**
     * verifyPageResponse() cannot send a request body, so post the form directly.
     *
     * @param array<string, string> $parameters
     */
    private function postVerify(int $judgingId, array $parameters): void
    {
        // Send the referer a browser would: the page holding the form. BrowserKit would
        // otherwise reuse the previous request's URI, a POST-only route.
        $this->client->request(
            'POST',
            sprintf('/jury/%d/verify', $judgingId),
            $parameters,
            [],
            ['HTTP_REFERER' => 'http://localhost' . static::$baseURL]
        );
        $response = $this->client->getResponse();
        self::assertEquals(302, $response->getStatusCode(), (string)$response->getContent());
    }

    /**
     * The request wrote through its own entity manager; start from a clean identity map.
     */
    private function reloadJudging(int $judgingId): Judging
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(Judging::class)->find($judgingId);
    }

    /**
     * Marking a judging verified records the verdict as checked and remembers which jury
     * member checked it; unmarking clears the jury member again.
     */
    public function testVerifyAndUnverifyJudging(): void
    {
        $judgingId = $this->addSubmission()->getJudgings()->first()->getJudgingid();

        $this->postVerify($judgingId, ['verified' => '1', 'comment' => 'looks right']);

        $judging = $this->reloadJudging($judgingId);
        self::assertTrue($judging->getVerified());
        self::assertSame('demo', $judging->getJuryMember());
        self::assertSame('looks right', $judging->getVerifyComment());

        $this->postVerify($judgingId, ['verified' => '0']);

        $judging = $this->reloadJudging($judgingId);
        self::assertFalse($judging->getVerified());
        self::assertNull($judging->getJuryMember(), 'unverifying must release the claim');
    }

    /**
     * When verification is required, the verification is also logged to the event feed.
     */
    public function testVerifyingLogsAnEventWhenVerificationIsRequired(): void
    {
        $judgingId = $this->addSubmission()->getJudgings()->first()->getJudgingid();

        $this->withChangedConfiguration('verification_required', true, function () use ($judgingId): void {
            $count = fn(): int => (int)$this->em()->getConnection()->fetchOne(
                "SELECT COUNT(*) FROM event WHERE endpointtype = 'judgements' AND endpointid = ?",
                [(string)$judgingId]
            );
            $before = $count();

            $this->postVerify($judgingId, ['verified' => '1', 'comment' => 'looks right']);

            self::assertGreaterThan($before, $count(), 'verifying must log an event for the judging');
        });
    }

    private function makePass(int $pass, string $result, string $outputRun, ?string $input = null): JudgingRunPass
    {
        return (new JudgingRunPass())
            ->setPass($pass)
            ->setRunresult($result)
            ->setRuntime(0.1)
            ->setStartTime(1000.0)
            ->setEndtime(1001.0)
            ->setOutput(
                (new JudgingRunPassOutput())
                    ->setInput($input)
                    ->setOutputRun($outputRun)
                    ->setOutputDiff('')
                    ->setOutputError('')
                    ->setOutputSystem('')
            );
    }

    public function testMultipassPerPassOutputAndInputDownloads(): void
    {
        $this->logIn();
        $submission = $this->addSubmission();
        $submission->getProblem()->setTypesAsString(['pass-fail', 'multi-pass']);

        /** @var Judging $judging */
        $judging = $submission->getJudgings()->first();
        $judging->setStarttime(1000.0)->setEndtime(1002.0)->setResult('wrong-answer');

        $pass1Out = "pass 1\n[output storage truncated after 6 B]";
        $pass2In = "validator\x00\xffnextpass";
        $pass2Out = "team\x00\xfeoutput";

        /** @var JudgingRun $run */
        $run = $judging->getRuns()->first();
        $run->setStarttime(1000.0)->setEndtime(1002.0)->setRuntime(0.1)->setRunresult('wrong-answer')->setPass(2)
            ->setOutput((new JudgingRunOutput())->setOutputRun($pass2Out)->setOutputDiff('')->setOutputError('')->setOutputSystem(''))
            ->addPass($this->makePass(1, 'correct', $pass1Out))
            ->addPass($this->makePass(2, 'wrong-answer', $pass2Out, $pass2In));
        $this->em()->flush();

        $baseUrl = sprintf('/jury/contests/demo/submissions/%s', $submission->getExternalid());
        $runUrl = sprintf('%s/runs/%d/passes', $baseUrl, $run->getRunid());
        $crawler = $this->client->request('GET', $baseUrl);

        self::assertStringContainsString('Truncated', $crawler->filter(sprintf('a[href="%s/1/team-output"]', $runUrl))->text());
        self::assertStringNotContainsString('Truncated', $crawler->filter(sprintf('a[href="%s/2/team-output"]', $runUrl))->text());
        self::assertCount(1, $crawler->filter(sprintf('a[href="/jury/request-output/%d/%d"]', $judging->getJudgingid(), $run->getRunid())));
        self::assertCount(0, $crawler->filter(sprintf('a[href="%s/1/input"]', $runUrl)));
        self::assertCount(1, $crawler->filter(sprintf('a[href="%s/2/input"]', $runUrl)));

        foreach ([
            "$runUrl/1/team-output" => $pass1Out,
            "$runUrl/2/team-output" => $pass2Out,
            "$runUrl/2/input" => $pass2In,
        ] as $url => $expectedBytes) {
            $this->client->request('GET', $url);
            self::assertEquals(200, $this->client->getResponse()->getStatusCode());
            self::assertSame($expectedBytes, $this->client->getInternalResponse()->getContent());
        }

        $this->client->request('GET', "$runUrl/1/input");
        self::assertEquals(404, $this->client->getResponse()->getStatusCode());
    }
}
