<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use App\Service\RenewalCalculator;
use App\Service\RenewalReminderPlanner;
use App\Service\TimezoneService;
use PHPUnit\Framework\TestCase;

/**
 * Pure scheduling seam: lead time is measured in calendar days in the User's
 * account time zone. No database, no mailer.
 */
final class RenewalReminderPlannerTest extends TestCase
{
    private RenewalReminderPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new RenewalReminderPlanner(new RenewalCalculator(), new TimezoneService());
    }

    public function testOptedOutUserNeverHasDueRenewal(): void
    {
        $user = $this->user('UTC', false, 3);
        $subscription = $this->subscription(BillingCycle::Monthly, '2024-03-13');

        self::assertNull($this->planner->dueRenewalDate(
            $subscription,
            $user,
            new \DateTimeImmutable('2024-03-10 12:00:00', new \DateTimeZone('UTC')),
        ));
    }

    public function testRenewalExactlyAtLeadTimeIsDue(): void
    {
        $user = $this->user('UTC', true, 3);

        $due = $this->planner->dueRenewalDate(
            $this->subscription(BillingCycle::Monthly, '2024-03-13'),
            $user,
            new \DateTimeImmutable('2024-03-10 12:00:00', new \DateTimeZone('UTC')),
        );

        self::assertNotNull($due);
        self::assertSame('2024-03-13', $due->format('Y-m-d'));
    }

    public function testRenewalBeyondLeadTimeIsNotDue(): void
    {
        $user = $this->user('UTC', true, 3);

        self::assertNull($this->planner->dueRenewalDate(
            $this->subscription(BillingCycle::Monthly, '2024-03-14'),
            $user,
            new \DateTimeImmutable('2024-03-10 12:00:00', new \DateTimeZone('UTC')),
        ));
    }

    public function testRenewalTodayIsDue(): void
    {
        $user = $this->user('UTC', true, 1);

        $due = $this->planner->dueRenewalDate(
            $this->subscription(BillingCycle::Monthly, '2024-03-10'),
            $user,
            new \DateTimeImmutable('2024-03-10 12:00:00', new \DateTimeZone('UTC')),
        );

        self::assertNotNull($due);
        self::assertSame('2024-03-10', $due->format('Y-m-d'));
    }

    public function testPastAnchorRollsForwardBeforeMeasuringLeadTime(): void
    {
        // Monthly anchor long past: next occurrence on/after 2024-03-10 is 2024-03-13.
        $user = $this->user('UTC', true, 3);

        $due = $this->planner->dueRenewalDate(
            $this->subscription(BillingCycle::Monthly, '2023-11-13'),
            $user,
            new \DateTimeImmutable('2024-03-10 12:00:00', new \DateTimeZone('UTC')),
        );

        self::assertNotNull($due);
        self::assertSame('2024-03-13', $due->format('Y-m-d'));
    }

    public function testLeadTimeUsesAccountLocalCalendarDayNotUtc(): void
    {
        // 02:00 UTC on Mar 10 is still Mar 9 (21:00 EST) in New York, so a
        // Mar-12 renewal is 3 local days out even though it is 2 UTC days out.
        $user = $this->user('America/New_York', true, 3);

        $due = $this->planner->dueRenewalDate(
            $this->subscription(BillingCycle::Monthly, '2024-03-12'),
            $user,
            new \DateTimeImmutable('2024-03-10 02:00:00', new \DateTimeZone('UTC')),
        );

        self::assertNotNull($due);
        self::assertSame('2024-03-12', $due->format('Y-m-d'));
    }

    public function testSpringForwardBoundaryKeepsCalendarDayMath(): void
    {
        // US daylight saving starts 2024-03-10 (02:00 -> 03:00 EST -> EDT).
        // Noon UTC on Mar 10 is 07:00 EST in New York: local day Mar 10.
        $user = $this->user('America/New_York', true, 3);

        $due = $this->planner->dueRenewalDate(
            $this->subscription(BillingCycle::Monthly, '2024-03-13'),
            $user,
            new \DateTimeImmutable('2024-03-10 12:00:00', new \DateTimeZone('UTC')),
        );

        self::assertNotNull($due);
        self::assertSame('2024-03-13', $due->format('Y-m-d'));
    }

    public function testFallBackBoundaryKeepsCalendarDayMath(): void
    {
        // Europe/Warsaw falls back 2024-10-27 (03:00 -> 02:00 CEST -> CET).
        // 22:30 UTC on Oct 26 is 00:30 local on Oct 27: a monthly anchor on
        // the 27th renews that same local day.
        $user = $this->user('Europe/Warsaw', true, 1);

        $due = $this->planner->dueRenewalDate(
            $this->subscription(BillingCycle::Monthly, '2024-09-27'),
            $user,
            new \DateTimeImmutable('2024-10-26 22:30:00', new \DateTimeZone('UTC')),
        );

        self::assertNotNull($due);
        self::assertSame('2024-10-27', $due->format('Y-m-d'));
    }

    public function testInvalidTimezoneFallsBackToUtc(): void
    {
        $user = $this->user('UTC', true, 3);
        $user->setTimezone('Not/AZone');

        $due = $this->planner->dueRenewalDate(
            $this->subscription(BillingCycle::Monthly, '2024-03-13'),
            $user,
            new \DateTimeImmutable('2024-03-10 12:00:00', new \DateTimeZone('UTC')),
        );

        self::assertNotNull($due);
        self::assertSame('2024-03-13', $due->format('Y-m-d'));
    }

    public function testMissingAnchorOrCycleIsNeverDue(): void
    {
        $user = $this->user('UTC', true, 3);

        $anchorless = (new Subscription())->setBillingCycle(BillingCycle::Monthly);
        self::assertNull($this->planner->dueRenewalDate(
            $anchorless,
            $user,
            new \DateTimeImmutable('2024-03-10 12:00:00', new \DateTimeZone('UTC')),
        ));

        $cycleless = (new Subscription())->setNextPayment(new \DateTimeImmutable('2024-03-13'));
        self::assertNull($this->planner->dueRenewalDate(
            $cycleless,
            $user,
            new \DateTimeImmutable('2024-03-10 12:00:00', new \DateTimeZone('UTC')),
        ));
    }

    private function user(string $timezone, bool $optIn, int $leadDays): User
    {
        return (new User())
            ->setEmail('planner@example.com')
            ->setPassword('none')
            ->setTimezone($timezone)
            ->setEmailRemindersEnabled($optIn)
            ->setReminderLeadDays($leadDays);
    }

    private function subscription(BillingCycle $cycle, string $nextPayment): Subscription
    {
        return (new Subscription())
            ->setBillingCycle($cycle)
            ->setNextPayment(new \DateTimeImmutable($nextPayment));
    }
}
