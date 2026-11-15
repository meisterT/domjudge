<?php declare(strict_types=1);

namespace App\DataFixtures\Test;

use App\Entity\Team;
use App\Entity\User;
use App\Entity\TeamCategory;
use Doctrine\Persistence\ObjectManager;

class SubmissionRateLimitFixture extends AbstractTestDataFixture
{
    public function load(ObjectManager $manager): void
    {
        $category = $manager->getRepository(TeamCategory::class)->findOneBy([]);

        $team = new Team();
        $team->setName('ratelimit-team');
        if ($category) {
            $team->addCategory($category);
        }
        $manager->persist($team);

        $user = new User();
        $user
            ->setUsername('ratelimit-user')
            ->setName('ratelimit-user')
            ->setPlainPassword('ratelimit-password')
            ->setTeam($team)
            ->addUserRole(BaselineData::role($manager, 'team'));
        $manager->persist($user);

        $juryTeam = new Team();
        $juryTeam->setName('ratelimit-jury-team');
        if ($category) {
            $juryTeam->addCategory($category);
        }
        $manager->persist($juryTeam);

        $juryUser = new User();
        $juryUser
            ->setUsername('ratelimit-jury')
            ->setName('ratelimit-jury')
            ->setPlainPassword('ratelimit-jury-password')
            ->setTeam($juryTeam)
            ->addUserRole(BaselineData::role($manager, 'team'))
            ->addUserRole(BaselineData::role($manager, 'jury'));
        $manager->persist($juryUser);

        // Make sure teams are in the demo contest
        $contest = BaselineData::contest($manager);
        if (!$contest->getTeams()->contains($team)) {
            $contest->addTeam($team);
        }
        if (!$contest->getTeams()->contains($juryTeam)) {
            $contest->addTeam($juryTeam);
        }

        $manager->flush();

        $this->addReference(static::class . ':team', $team);
        $this->addReference(static::class . ':user', $user);
        $this->addReference(static::class . ':jury-team', $juryTeam);
        $this->addReference(static::class . ':jury-user', $juryUser);
    }
}
