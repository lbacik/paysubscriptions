<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\BillingCycle;
use App\Service\RenewalCalculator;
use PHPUnit\Framework\TestCase;

final class RenewalCalculatorTest extends TestCase
{
    public function testMonthlyAnchorOnOrdinaryDayRenewsOnSameDayNextMonth(): void
    {
        $calculator = new RenewalCalculator();

        $next = $calculator->nextRenewal(
            new \DateTimeImmutable('2024-01-15'),
            BillingCycle::Monthly,
            new \DateTimeImmutable('2024-01-16'),
        );

        self::assertSame('2024-02-15', $next->format('Y-m-d'));
    }

    public function testMonthlyAnchorOn31stFallsBackToFebruaryLastDay(): void
    {
        $calculator = new RenewalCalculator();

        $next = $calculator->nextRenewal(
            new \DateTimeImmutable('2024-01-31'),
            BillingCycle::Monthly,
            new \DateTimeImmutable('2024-02-01'),
        );

        self::assertSame('2024-02-29', $next->format('Y-m-d'));
    }

    public function testMonthlyAnchorReturnsTo31stAfterShortFebruary(): void
    {
        $calculator = new RenewalCalculator();

        $next = $calculator->nextRenewal(
            new \DateTimeImmutable('2024-01-31'),
            BillingCycle::Monthly,
            new \DateTimeImmutable('2024-03-01'),
        );

        self::assertSame('2024-03-31', $next->format('Y-m-d'));
    }

    public function testYearlyAnchorRenewsInSameMonthNextYear(): void
    {
        $calculator = new RenewalCalculator();

        $next = $calculator->nextRenewal(
            new \DateTimeImmutable('2024-05-20'),
            BillingCycle::Yearly,
            new \DateTimeImmutable('2024-06-01'),
        );

        self::assertSame('2025-05-20', $next->format('Y-m-d'));
    }

    public function testYearlyLeapDayAnchorFallsBackToFebruary28th(): void
    {
        $calculator = new RenewalCalculator();

        $next = $calculator->nextRenewal(
            new \DateTimeImmutable('2024-02-29'),
            BillingCycle::Yearly,
            new \DateTimeImmutable('2025-01-01'),
        );

        self::assertSame('2025-02-28', $next->format('Y-m-d'));
    }

    public function testYearlyLeapDayAnchorReturnsToFebruary29thOnLeapYear(): void
    {
        $calculator = new RenewalCalculator();

        $next = $calculator->nextRenewal(
            new \DateTimeImmutable('2024-02-29'),
            BillingCycle::Yearly,
            new \DateTimeImmutable('2027-06-01'),
        );

        self::assertSame('2028-02-29', $next->format('Y-m-d'));
    }

    public function testPastMonthlyAnchorRollsForwardToFirstOccurrenceOnOrAfterReference(): void
    {
        $calculator = new RenewalCalculator();

        $next = $calculator->nextRenewal(
            new \DateTimeImmutable('2022-01-31'),
            BillingCycle::Monthly,
            new \DateTimeImmutable('2023-06-15'),
        );

        self::assertSame('2023-06-30', $next->format('Y-m-d'));
    }

    public function testAnchorEqualToReferenceIsDueThatDay(): void
    {
        $calculator = new RenewalCalculator();

        $next = $calculator->nextRenewal(
            new \DateTimeImmutable('2024-04-10'),
            BillingCycle::Monthly,
            new \DateTimeImmutable('2024-04-10'),
        );

        self::assertSame('2024-04-10', $next->format('Y-m-d'));
    }

    public function testEditedAnchorMovesSubsequentRenewalsToNewDay(): void
    {
        $calculator = new RenewalCalculator();

        $beforeEdit = $calculator->nextRenewal(
            new \DateTimeImmutable('2024-01-31'),
            BillingCycle::Monthly,
            new \DateTimeImmutable('2024-05-01'),
        );
        $afterEdit = $calculator->nextRenewal(
            new \DateTimeImmutable('2024-02-15'),
            BillingCycle::Monthly,
            new \DateTimeImmutable('2024-05-01'),
        );

        self::assertSame('2024-05-31', $beforeEdit->format('Y-m-d'));
        self::assertSame('2024-05-15', $afterEdit->format('Y-m-d'));
    }

    public function testUpcomingRenewalsListPreservesAnchorAcrossMonthEnds(): void
    {
        $calculator = new RenewalCalculator();

        $renewals = $calculator->upcomingRenewals(
            new \DateTimeImmutable('2024-01-31'),
            BillingCycle::Monthly,
            new \DateTimeImmutable('2024-02-15'),
            3,
        );

        self::assertSame(
            ['2024-02-29', '2024-03-31', '2024-04-30'],
            array_map(fn(\DateTimeImmutable $date) => $date->format('Y-m-d'), $renewals),
        );
    }
}
