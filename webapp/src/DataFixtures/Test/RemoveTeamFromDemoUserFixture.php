<?php declare(strict_types=1);

namespace App\DataFixtures\Test;

use Doctrine\Persistence\ObjectManager;

class RemoveTeamFromDemoUserFixture extends AbstractTestDataFixture
{
    public function load(ObjectManager $manager): void
    {
        $user = BaselineData::user($manager);
        $user->setTeam();
        $manager->flush();
    }
}
