<?php declare(strict_types=1);

namespace App\DataFixtures\Test;

use Doctrine\Persistence\ObjectManager;

class RemoveTeamFromAdminUserFixture extends AbstractTestDataFixture
{
    public function load(ObjectManager $manager): void
    {
        $user = BaselineData::user($manager, BaselineData::USER_ADMIN);
        $user->setTeam();
        $manager->flush();
    }
}
