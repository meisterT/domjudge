<?php declare(strict_types=1);

namespace App\DataFixtures\Test;

use App\Entity\User;
use Doctrine\Persistence\ObjectManager;

class SelfRegisteredUserFixture extends AbstractTestDataFixture
{
    public function load(ObjectManager $manager): void
    {
        $user = new User();
        $user
            ->setExternalid('selfregister')
            ->setUsername('selfregister')
            ->setName('selfregistered user for example team')
            ->setPlainPassword('demo')
            ->setTeam(BaselineData::team($manager))
            ->addUserRole(BaselineData::role($manager, 'team'));

        $manager->persist($user);
        $manager->flush();
    }
}
