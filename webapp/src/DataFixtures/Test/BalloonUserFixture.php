<?php declare(strict_types=1);

namespace App\DataFixtures\Test;

use App\Entity\User;
use Doctrine\Persistence\ObjectManager;

class BalloonUserFixture extends AbstractTestDataFixture
{
    public function load(ObjectManager $manager): void
    {
        $user = new User();
        $user
            ->setExternalid('balloonuser')
            ->setUsername('balloonuser')
            ->setName('User for balloon runners')
            ->setPlainPassword('balloonuser')
            ->addUserRole(BaselineData::role($manager, 'balloon'));

        $manager->persist($user);
        $manager->flush();
    }
}
