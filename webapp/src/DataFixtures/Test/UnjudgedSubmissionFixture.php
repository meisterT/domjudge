<?php declare(strict_types=1);

namespace App\DataFixtures\Test;

use App\Entity\Contest;
use App\Entity\Judging;
use App\Entity\Language;
use App\Entity\Submission;
use App\Utils\Utils;
use Doctrine\Persistence\ObjectManager;

/**
 * A valid submission whose valid judging has no result yet, i.e. one that
 * blocks finalizing the contest.
 */
class UnjudgedSubmissionFixture extends AbstractTestDataFixture
{
    final public const EXTERNAL_ID = 'unjudged-1';

    public function load(ObjectManager $manager): void
    {
        /** @var Contest $contest */
        $contest = BaselineData::contest($manager);
        $problem = BaselineData::contestProblem($manager);

        $submission = (new Submission())
            ->setExternalid(static::EXTERNAL_ID)
            ->setContest($contest)
            ->setTeam(BaselineData::team($manager))
            ->setContestProblem($problem)
            ->setLanguage($manager->getRepository(Language::class)->findByExternalId('cpp'))
            ->setSubmittime(Utils::toEpochFloat('2021-01-01 12:34:56'));
        $judging = (new Judging())
            ->setContest($contest)
            ->setSubmission($submission)
            ->setStarttime(Utils::toEpochFloat('2021-01-01 12:34:56'));
        $submission->addJudging($judging);

        $manager->persist($submission);
        $manager->persist($judging);
        $manager->flush();

        $this->addReference(sprintf('%s:0', static::class), $submission);
    }
}
