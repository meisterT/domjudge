<?php declare(strict_types=1);

namespace App\Tests\Unit\Controller\API;

use App\DataFixtures\Test\DemoPreFreezeContestFixture;
use App\Entity\Contest;
use App\Entity\Judging;
use App\Entity\JudgingRun;
use App\Entity\Language;
use App\Entity\Submission;
use App\Entity\Team;
use App\Utils\Utils;
use Doctrine\ORM\EntityManagerInterface;

class RunControllerTest extends BaseTestCase
{
    protected ?string $apiEndpoint = null;

    protected ?string $apiUser = 'admin';

    public function testListExcludesInvalidatedJudgingRuns(): void
    {
        $this->loadFixtures([DemoPreFreezeContestFixture::class]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $contest = $em->getRepository(Contest::class)->findOneBy(['shortname' => 'demo']);
        $contestProblem = $contest->getProblems()->first();
        $testcase = $contestProblem->getProblem()->getTestcases()->first();
        $submittime = Utils::toEpochFloat('2021-02-15 10:00:00');

        $submission = (new Submission())
            ->setContest($contest)
            ->setTeam($em->getRepository(Team::class)->findOneBy(['name' => 'Example teamname']))
            ->setContestProblem($contestProblem)
            ->setLanguage($em->getRepository(Language::class)->findByExternalId('c'))
            ->setSubmittime($submittime);
        $em->persist($submission);

        $runsByValidity = [];
        foreach ([false, true] as $offset => $valid) {
            $judging = (new Judging())
                ->setContest($contest)
                ->setSubmission($submission)
                ->setStarttime($submittime + $offset)
                ->setEndtime($submittime + $offset + 2)
                ->setValid($valid)
                ->setResult('correct');
            $submission->addJudging($judging);
            $run = (new JudgingRun())
                ->setJudging($judging)
                ->setTestcase($testcase)
                ->setRunresult('correct')
                ->setRuntime(0.01)
                ->setStartTime($submittime + $offset)
                ->setEndtime($submittime + $offset + 1);
            $judging->addRun($run);
            $em->persist($judging);
            $em->persist($run);
            $runsByValidity[(int)$valid] = $run;
        }
        $em->flush();

        $contestId = $this->getDemoContestId();
        $runIds = array_column(
            $this->verifyApiJsonResponse('GET', "/contests/$contestId/runs", 200, $this->apiUser),
            'id'
        );
        self::assertContains((string)$runsByValidity[1]->getRunid(), $runIds);
        self::assertNotContains((string)$runsByValidity[0]->getRunid(), $runIds);

        $byIds = $this->verifyApiJsonResponse(
            'GET',
            sprintf('/contests/%s/runs?ids[]=%d', $contestId, $runsByValidity[0]->getRunid()),
            200,
            $this->apiUser
        );
        self::assertCount(1, $byIds);
    }
}
