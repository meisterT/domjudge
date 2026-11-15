<?php declare(strict_types=1);

namespace App\DataFixtures\Test;

use Doctrine\Persistence\ObjectManager;

class AddLocationToTeamFixture extends AbstractTestDataFixture
{
    public function load(ObjectManager $manager): void
    {
        $team = BaselineData::team($manager);
        $team->setLocation('Utrecht');
        $manager->flush();
    }
}
