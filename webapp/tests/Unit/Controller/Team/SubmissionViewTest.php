<?php declare(strict_types=1);

namespace App\Tests\Unit\Controller\Team;

use App\Entity\Contest;
use App\Entity\ContestProblem;
use App\Entity\Judging;
use App\Entity\JudgingRun;
use App\Entity\JudgingRunOutput;
use App\Entity\Language;
use App\Entity\Submission;
use App\Entity\Team;
use App\Entity\Testcase;
use App\Tests\Unit\BaseTestCase;
use Doctrine\ORM\EntityManagerInterface;

class SubmissionViewTest extends BaseTestCase
{
    protected array $roles = ['team'];

    /**
     * A submission that does not exist or belongs to another team has no judging for this
     * team. The page should say so, also when sample output is shown.
     */
    public function testUnknownSubmissionWithSampleOutput(): void
    {
        $this->withChangedConfiguration('show_sample_output', true, function (): void {
            $this->verifyPageResponse('GET', '/team/submission/4242', 200);
            static::assertStringContainsString(
                'Submission not found for this team or not judged yet.',
                $this->client->getResponse()->getContent()
            );
        });
    }

    public function testSampleOutputAndMemoryUsageRespectsShowLimits(): void
    {
        $submitId = $this->createSubmissionWithSampleAndSecretRuns('team-view-sample-limits');

        $this->withChangedConfiguration('show_sample_output', true, function () use ($submitId): void {
            $this->withChangedConfiguration('show_limits_on_team_page', true, function () use ($submitId): void {
                $this->verifyPageResponse('GET', '/team/submission/' . $submitId, 200);
                $content = $this->client->getResponse()->getContent();

                static::assertStringContainsString('Sample testcase description', $content);
                static::assertStringContainsString('sample program output', $content);
                static::assertStringContainsString('Memory usage', $content);
                static::assertStringContainsString('1 MB', $content);
                static::assertStringNotContainsString('Secret testcase description', $content);
                static::assertStringNotContainsString('secret program output', $content);
            });

            $this->withChangedConfiguration('show_limits_on_team_page', false, function () use ($submitId): void {
                $this->verifyPageResponse('GET', '/team/submission/' . $submitId, 200);
                $content = $this->client->getResponse()->getContent();

                static::assertStringContainsString('sample program output', $content);
                static::assertStringNotContainsString('Runtime', $content);
                static::assertStringNotContainsString('Memory usage', $content);
            });
        });
    }

    private function createSubmissionWithSampleAndSecretRuns(string $externalId): string
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var Contest $contest */
        $contest = $em->getRepository(Contest::class)->findOneBy(['shortname' => 'demo']);
        /** @var Team $team */
        $team = $em->getRepository(Team::class)->findOneBy(['name' => 'Example teamname']);
        /** @var Language $language */
        $language = $em->getRepository(Language::class)->findByExternalId('cpp');
        /** @var ContestProblem $contestProblem */
        $contestProblem = $contest->getProblems()
            ->filter(fn (ContestProblem $problem) => $problem->getShortname() === 'A')
            ->first();
        $problem = $contestProblem->getProblem();

        $maxRank = 0;
        foreach ($problem->getTestcases() as $existingTestcase) {
            $maxRank = max($maxRank, $existingTestcase->getRank());
        }

        $sampleTestcase = (new Testcase())
            ->setProblem($problem)
            ->setRank($maxRank + 1)
            ->setSample(true)
            ->setDescription('Sample testcase description')
            ->setMd5sumInput(md5('sample-in'))
            ->setMd5sumOutput(md5('sample-out'));
        $secretFailTestcase = (new Testcase())
            ->setProblem($problem)
            ->setRank($maxRank + 2)
            ->setSample(false)
            ->setDescription('Secret testcase description')
            ->setMd5sumInput(md5('secret1-in'))
            ->setMd5sumOutput(md5('secret1-out'));
        $secretAfterFailTestcase = (new Testcase())
            ->setProblem($problem)
            ->setRank($maxRank + 3)
            ->setSample(false)
            ->setDescription('Secret after fail description')
            ->setMd5sumInput(md5('secret2-in'))
            ->setMd5sumOutput(md5('secret2-out'));

        $submitTime = $contest->getStarttime() + 60;
        $submission = (new Submission())
            ->setExternalid($externalId)
            ->setContest($contest)
            ->setTeam($team)
            ->setContestProblem($contestProblem)
            ->setLanguage($language)
            ->setSubmittime($submitTime);
        $judging = (new Judging())
            ->setContest($contest)
            ->setStarttime($submitTime)
            ->setEndtime($submitTime + 5)
            ->setValid(true)
            ->setSubmission($submission)
            ->setResult('wrong-answer');

        $runsSpec = [
            [$sampleTestcase, 'correct', "cpu-time: 0.01\nmemory-bytes: 1048576\n", 'sample program output'],
            [$secretFailTestcase, 'wrong-answer', "cpu-time: 0.02\n", 'secret program output'],
            [$secretAfterFailTestcase, 'timelimit', "cpu-time: 1.00\nmemory-bytes: 2097152\n", 'secret after fail output'],
        ];

        $em->persist($sampleTestcase);
        $em->persist($secretFailTestcase);
        $em->persist($secretAfterFailTestcase);
        $em->persist($submission);
        $em->persist($judging);

        foreach ($runsSpec as [$testcase, $result, $metadata, $outputRun]) {
            $judgingRun = (new JudgingRun())
                ->setJudging($judging)
                ->setTestcase($testcase)
                ->setStarttime($submitTime)
                ->setEndtime($submitTime + 1)
                ->setRunresult($result)
                ->setRuntime(0.01);
            $output = (new JudgingRunOutput())
                ->setRun($judgingRun)
                ->setMetadata($metadata)
                ->setOutputRun($outputRun);
            $judgingRun->setOutput($output);
            $em->persist($judgingRun);
            $em->persist($output);
        }

        $em->flush();

        return $externalId;
    }
}
