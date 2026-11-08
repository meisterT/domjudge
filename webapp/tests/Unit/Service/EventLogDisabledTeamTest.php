<?php declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\DataFixtures\Test\DisableDemoTeamFixture;
use App\Entity\Contest;
use App\Entity\Event;
use App\Entity\Team;
use App\Entity\User;
use App\Service\EventLogService;
use App\Tests\Unit\BaseTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class EventLogDisabledTeamTest extends BaseTestCase
{
    /**
     * Disabling a team must produce an update event that marks it as hidden, so that
     * event feed consumers stop showing it. Without the team in the API, no event was logged.
     */
    public function testDisablingTeamLogsHiddenUpdate(): void
    {
        $this->loadFixture(DisableDemoTeamFixture::class);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $team = $em->getRepository(Team::class)->findOneBy(['externalid' => 'exteam']);
        $contest = $em->getRepository(Contest::class)->findOneBy(['shortname' => 'demo']);

        // Events are logged while a jury member is logged in, as when the team is disabled in the jury interface.
        $juryUser = $em->getRepository(User::class)->findOneBy(['username' => 'disabledteam-jury']);
        static::getContainer()->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($juryUser, 'main', $juryUser->getRoles()));

        static::getContainer()->get(EventLogService::class)
            ->log('teams', $team->getTeamid(), EventLogService::ACTION_UPDATE, $contest->getCid());

        /** @var Event|null $event */
        $event = $em->getRepository(Event::class)->findOneBy(
            ['endpointtype' => 'teams', 'endpointid' => 'exteam', 'action' => EventLogService::ACTION_UPDATE],
            ['eventid' => 'DESC']
        );
        static::assertNotNull($event, 'No update event was logged for the disabled team.');
        static::assertTrue($event->getContent()['hidden']);
    }
}
