<?php declare(strict_types=1);

namespace App\DataFixtures\Test;

use App\Entity\Contest;
use App\Entity\ContestProblem;
use App\Entity\JudgeTask;
use App\Entity\Language;
use App\Entity\Problem;
use App\Entity\QueueTask;
use App\Entity\Role;
use App\Entity\Submission;
use App\Entity\Team;
use App\Entity\TeamAffiliation;
use App\Entity\User;
use Doctrine\Persistence\ObjectManager;
use LogicException;

/**
 * The data that tests and test fixtures assume to exist already.
 *
 * It is created by the installation and not by the tests themselves: a
 * bare install followed by the pass-fail examples, without the example
 * submissions, and with the admin user in the DOMjudge team (see
 * webapp/tests/setup-test-database.sh). Look the rows up through this class,
 * so that the dependency is in one place and a deviating baseline fails with
 * an explanation.
 */
final class BaselineData
{
    public const CONTEST_SHORTNAME = 'demo';
    public const CONTEST_NAME      = 'Demo contest';

    public const USER_ADMIN = 'admin';
    public const USER_DEMO  = 'demo';

    public const TEAM_EXTERNALID = 'exteam';
    public const TEAM_NAME       = 'Example teamname';

    public const TEAM_SYSTEM_NAME = 'DOMjudge';

    public const AFFILIATION_EXTERNALID = 'utrecht';

    public const PROBLEM_HELLO      = 'hello';
    public const PROBLEM_HELLO_NAME = 'Hello World';
    public const PROBLEM_BOOLFIND = 'boolfind';
    public const PROBLEM_FLTCMP   = 'fltcmp';

    public const LANGUAGE_CPP = 'cpp';

    /** The problems of the pass-fail examples, which are the only ones in the baseline. */
    public const PROBLEMS = ['hello', 'fltcmp', 'boolfind', 'jumble', 'hangman'];

    public static function contest(ObjectManager $em): Contest
    {
        return self::require(
            $em->getRepository(Contest::class)->findOneBy(['shortname' => self::CONTEST_SHORTNAME]),
            sprintf("contest '%s'", self::CONTEST_SHORTNAME)
        );
    }

    /**
     * The problem with the given external ID as it is in the demo contest, where the baseline
     * labels hello 'A', fltcmp 'B' and boolfind 'C'.
     */
    public static function contestProblem(ObjectManager $em, string $problemExternalId = self::PROBLEM_HELLO): ContestProblem
    {
        return self::require(
            $em->getRepository(ContestProblem::class)->findOneBy([
                'contest' => self::contest($em),
                'problem' => self::problem($em, $problemExternalId),
            ]),
            sprintf("problem '%s' in contest '%s'", $problemExternalId, self::CONTEST_SHORTNAME)
        );
    }

    public static function team(ObjectManager $em): Team
    {
        return self::require(
            $em->getRepository(Team::class)->findOneBy(['externalid' => self::TEAM_EXTERNALID]),
            sprintf("team '%s'", self::TEAM_EXTERNALID)
        );
    }

    /**
     * The team that belongs to the admin user, in the hidden System category.
     */
    public static function affiliation(ObjectManager $em): TeamAffiliation
    {
        return self::require(
            $em->getRepository(TeamAffiliation::class)->findOneBy(['externalid' => self::AFFILIATION_EXTERNALID]),
            sprintf("affiliation '%s'", self::AFFILIATION_EXTERNALID)
        );
    }

    public static function systemTeam(ObjectManager $em): Team
    {
        return self::require(
            $em->getRepository(Team::class)->findOneBy(['name' => self::TEAM_SYSTEM_NAME]),
            sprintf("team '%s'", self::TEAM_SYSTEM_NAME)
        );
    }

    public static function role(ObjectManager $em, string $djRole): Role
    {
        return self::require(
            $em->getRepository(Role::class)->findOneBy(['dj_role' => $djRole]),
            sprintf("role '%s'", $djRole)
        );
    }

    public static function user(ObjectManager $em, string $username = self::USER_DEMO): User
    {
        return self::require(
            $em->getRepository(User::class)->findOneBy(['username' => $username]),
            sprintf("user '%s'", $username)
        );
    }

    public static function problem(ObjectManager $em, string $externalId = self::PROBLEM_HELLO): Problem
    {
        return self::require(
            $em->getRepository(Problem::class)->findOneBy(['externalid' => $externalId]),
            sprintf("problem '%s'", $externalId)
        );
    }

    /**
     * Describe where the database deviates from the baseline, empty if it matches.
     *
     * Besides rows that are missing this also covers data that was added to
     * a database which has more than the baseline, for example by installing
     * the scoring examples or the example submissions.
     *
     * @return string[]
     */
    public static function deviations(ObjectManager $em): array
    {
        $expected = [
            [Contest::class, ['shortname' => self::CONTEST_SHORTNAME]],
            [Team::class, ['externalid' => self::TEAM_EXTERNALID]],
            [Team::class, ['name' => self::TEAM_SYSTEM_NAME]],
            [TeamAffiliation::class, ['externalid' => self::AFFILIATION_EXTERNALID]],
            [Language::class, ['externalid' => self::LANGUAGE_CPP]],
            [Role::class, ['dj_role' => 'team']],
            [Role::class, ['dj_role' => 'jury']],
            [Role::class, ['dj_role' => 'balloon']],
            [User::class, ['username' => self::USER_DEMO]],
            [User::class, ['username' => self::USER_ADMIN]],
        ];

        $deviations = [];
        foreach ($expected as [$class, $criteria]) {
            if ($em->getRepository($class)->findOneBy($criteria) === null) {
                $deviations[] = sprintf("missing %s '%s'", substr(strrchr($class, '\\'), 1), reset($criteria));
            }
        }

        $contests = array_map(static fn(Contest $c) => $c->getShortname(), $em->getRepository(Contest::class)->findAll());
        if ($contests !== [self::CONTEST_SHORTNAME]) {
            $deviations[] = sprintf(
                "expected only the contest '%s', found: %s",
                self::CONTEST_SHORTNAME,
                $contests === [] ? 'none' : implode(', ', $contests)
            );
        }

        $problems = array_map(static fn(Problem $p) => $p->getExternalid(), $em->getRepository(Problem::class)->findAll());
        if (array_diff(self::PROBLEMS, $problems) !== [] || array_diff($problems, self::PROBLEMS) !== []) {
            $deviations[] = sprintf(
                'expected exactly the problems %s, found: %s',
                implode(', ', self::PROBLEMS),
                $problems === [] ? 'none' : implode(', ', $problems)
            );
        }

        foreach ([Submission::class, JudgeTask::class, QueueTask::class] as $class) {
            $count = $em->getRepository($class)->count([]);
            if ($count !== 0) {
                $deviations[] = sprintf('expected no %s rows, found %d', substr(strrchr($class, '\\'), 1), $count);
            }
        }

        $admin = $em->getRepository(User::class)->findOneBy(['username' => self::USER_ADMIN]);
        if ($admin !== null && $admin->getTeam()?->getName() !== self::TEAM_SYSTEM_NAME) {
            $deviations[] = sprintf(
                "expected user '%s' to be in team '%s', found: %s",
                self::USER_ADMIN,
                self::TEAM_SYSTEM_NAME,
                $admin->getTeam()?->getName() ?? 'no team'
            );
        }

        return $deviations;
    }

    /**
     * @template T of object
     * @param T|null $entity
     * @return T
     */
    private static function require(?object $entity, string $description): object
    {
        if ($entity === null) {
            throw new LogicException(sprintf(
                'Baseline data missing: %s. The tests expect it in the test database, '
                . 'see webapp/tests/setup-test-database.sh.',
                $description
            ));
        }

        return $entity;
    }
}
