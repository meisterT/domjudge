<?php declare(strict_types=1);

namespace App\DataFixtures\Test;

use Doctrine\Persistence\ObjectManager;

class TeamWithExternalIdEqualsOneFixture extends AbstractTestDataFixture
{
    public function load(ObjectManager $manager): void
    {
        $team = BaselineData::systemTeam($manager);
        $team->setExternalid('1');
        $manager->flush();
    }
}
