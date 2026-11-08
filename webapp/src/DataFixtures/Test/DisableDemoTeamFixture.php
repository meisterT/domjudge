<?php declare(strict_types=1);

namespace App\DataFixtures\Test;

use App\Entity\Role;
use App\Entity\Team;
use App\Entity\User;
use Doctrine\Persistence\ObjectManager;

/**
 * Disables the team of the demo user and adds a jury user to look at it.
 */
class DisableDemoTeamFixture extends AbstractTestDataFixture
{
    public function load(ObjectManager $manager): void
    {
        $team = $manager->getRepository(Team::class)->findOneBy(['externalid' => 'exteam']);
        $team->setEnabled(false);

        $juryUser = (new User())
            ->setUsername('disabledteam-jury')
            ->setName('disabledteam-jury')
            ->setPlainPassword('disabledteam-jury')
            ->addUserRole($manager->getRepository(Role::class)->findOneBy(['dj_role' => 'jury']));
        $manager->persist($juryUser);
        $manager->flush();
    }
}
