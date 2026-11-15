<?php declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Contest;
use App\Utils\Utils;

/**
 * A state the demo contest of the baseline data can be put in.
 *
 * Pass a case to loadFixture() or loadFixtures() of the base test case, which applies it in the
 * order it is given, or to BaseTestCase::applyDemoContestState(). A state only sets the times it
 * is about and keeps the others, so combining states, or applying one to a contest whose times
 * were changed before, can give combinations of times that a real contest would not have.
 */
enum DemoContestState
{
    /** Activated next year, which is also when it starts. */
    case PreActivation;
    /** Activated this year, starts next year. */
    case PreStart;
    /** Activated this year, starts in 15 seconds. */
    case AboutToStart;
    /** Started, with the freeze next year. */
    case PreFreeze;
    /** Started and frozen, ends next year. */
    case PreEnd;
    /** Ended this year, with the unfreeze next year. */
    case PreUnfreeze;
    /** Ended, frozen and unfrozen in the past. */
    case PostUnfreeze;
    /** Ended last year and unfrozen this year, deactivated two years from now. */
    case PreDeactivate;
    /** Started two years ago and deactivated last year. */
    case PostDeactivate;
    /** Not visible to the public. */
    case NonPublic;
    /** Locked for changes. */
    case Locked;

    public function apply(Contest $contest): void
    {
        match ($this) {
            self::PreActivation => $contest
                ->setActivatetimeString(self::time(1, '01-01 09:00:00'))
                ->setStarttimeString(self::time(2, '01-01 09:00:00')),
            self::PreStart => $contest
                ->setActivatetimeString(self::time(0, '01-01 09:00:00'))
                ->setStarttimeString(self::time(1, '01-01 09:00:00')),
            self::AboutToStart => $contest
                ->setActivatetimeString(self::time(0, '01-01 09:00:00'))
                ->setStarttimeString(Utils::absTime(Utils::now() + 15)),
            self::PreFreeze => $contest
                ->setStarttimeString('2021-01-01 09:00:00 Europe/Amsterdam')
                ->setFreezetimeString(self::time(1, '01-01 09:00:00'))
                ->setEndtimeString(self::time(1, '02-01 09:00:00')),
            self::PreEnd => $contest
                ->setStarttimeString('2020-01-01 09:00:00 Europe/Amsterdam')
                ->setFreezetimeString('2021-01-03 12:34:56 Europe/Amsterdam')
                ->setEndtimeString(self::time(1, '01-01 09:00:00')),
            self::PreUnfreeze => $contest
                ->setStarttimeString(self::time(0, '01-01 00:00:00'))
                ->setEndtimeString(self::time(0, '01-01 09:00:00'))
                ->setFreezetimeString('2021-01-03 12:34:56 Europe/Amsterdam')
                ->setUnfreezetimeString(self::time(1, '01-01 09:00:00')),
            self::PostUnfreeze => $contest
                ->setStarttimeString('2021-01-03 12:34:56 Europe/Amsterdam')
                ->setEndtimeString('2021-01-04 12:34:56 Europe/Amsterdam')
                ->setFreezetimeString('2021-01-05 12:34:56 Europe/Amsterdam')
                ->setUnfreezetimeString('2021-01-06 12:34:56 Europe/Amsterdam'),
            self::PreDeactivate => $contest
                ->setStarttimeString(self::time(-1, '06-06 00:00:00'))
                ->setEndtimeString(self::time(-1, '06-06 09:00:00'))
                ->setUnfreezetimeString(self::time(0, '01-01 09:00:00'))
                ->setDeactivatetimeString(self::time(2, '01-01 09:00:00')),
            self::PostDeactivate => $contest
                ->setStarttimeString(self::time(-2, '01-01 09:00:00'))
                ->setDeactivatetimeString(self::time(-1, '01-01 09:00:00')),
            self::NonPublic => $contest->setPublic(false),
            self::Locked => $contest->setIsLocked(true),
        };
    }

    /**
     * A time in the given year, relative to this one, in the time zone the baseline uses.
     */
    private static function time(int $yearOffset, string $monthDayTime): string
    {
        return sprintf('%d-%s Europe/Amsterdam', (int)date('Y') + $yearOffset, $monthDayTime);
    }
}
