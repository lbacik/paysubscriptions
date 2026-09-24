<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Subscription;
use App\Enum\BillingCycle;
use App\Service\RenewalCalculator;
use App\Service\UpcomingRenewals;
use PHPUnit\Framework\TestCase;

final class UpcomingRenewalsTest extends TestCase
{
    private UpcomingRenewals $upcoming;

    protected function setUp(): void
    {
        $this->upcoming = new UpcomingRenewals(new RenewalCalculator());
    }

    public function testOrdersMixedMonthlyAndYearlyOccurrencesByDate(): void
    {
        $today = new \DateTimeImmutable('2024-03-10');

        $subscriptions = [
            $this->subscription('Late Monthly', BillingCycle::Monthly, '2024-04-01', 10.00),
            $this->subscription('Soon Yearly', BillingCycle::Yearly, '2024-03-12', 120.00),
            $this->subscription('Soonest Monthly', BillingCycle::Monthly, '2024-03-11', 9.99),
        ];

        $renewals = $this->upcoming->nextOccurrences($subscriptions, $today, 3);

        self::assertSame(
            ['Soonest Monthly', 'Soon Yearly', 'Late Monthly'],
            array_map(fn($renewal) => $renewal->subscription->getName(), $renewals),
        );
        self::assertSame(
            ['2024-03-11', '2024-03-12', '2024-04-01'],
            array_map(fn($renewal) => $renewal->renewalDate->format('Y-m-d'), $renewals),
        );
    }

    public function testLimitsOccurrencesToFive(): void
    {
        $today = new \DateTimeImmutable('2024-03-10');

        $subscriptions = [];
        for ($day = 11; $day <= 17; ++$day) {
            $subscriptions[] = $this->subscription(
                sprintf('Sub %02d', $day),
                BillingCycle::Monthly,
                sprintf('2024-03-%02d', $day),
                5.00,
            );
        }

        $renewals = $this->upcoming->nextOccurrences($subscriptions, $today);

        self::assertCount(5, $renewals);
        self::assertSame('2024-03-15', $renewals[4]->renewalDate->format('Y-m-d'));
    }

    public function testFillsWidgetWithLaterRepeatsWhenFewerThanFiveSubscriptions(): void
    {
        $today = new \DateTimeImmutable('2024-03-10');

        $renewals = $this->upcoming->nextOccurrences(
            [
                $this->subscription('First Monthly', BillingCycle::Monthly, '2024-03-11', 10.00),
                $this->subscription('Second Monthly', BillingCycle::Monthly, '2024-03-12', 20.00),
            ],
            $today,
        );

        self::assertSame(
            ['2024-03-11', '2024-03-12', '2024-04-11', '2024-04-12', '2024-05-11'],
            array_map(fn($renewal) => $renewal->renewalDate->format('Y-m-d'), $renewals),
        );
        self::assertSame(
            ['First Monthly', 'Second Monthly', 'First Monthly', 'Second Monthly', 'First Monthly'],
            array_map(fn($renewal) => $renewal->subscription->getName(), $renewals),
        );
    }

    public function testIncludesOccurrenceDueToday(): void
    {
        $today = new \DateTimeImmutable('2024-03-10');

        $renewals = $this->upcoming->nextOccurrences(
            [$this->subscription('Due Today', BillingCycle::Monthly, '2024-03-10', 7.50)],
            $today,
        );

        self::assertSame('2024-03-10', $renewals[0]->renewalDate->format('Y-m-d'));
    }

    public function testMonthEndAnchorFallsBackToLastDayOfMonth(): void
    {
        $today = new \DateTimeImmutable('2024-02-15');

        $renewals = $this->upcoming->nextOccurrences(
            [$this->subscription('Month End', BillingCycle::Monthly, '2024-01-31', 20.00)],
            $today,
        );

        self::assertSame(
            ['2024-02-29', '2024-03-31', '2024-04-30'],
            array_map(
                fn($renewal) => $renewal->renewalDate->format('Y-m-d'),
                \array_slice($renewals, 0, 3),
            ),
        );
    }

    public function testLeapDayYearlyAnchorFallsBackOutsideLeapYears(): void
    {
        $today = new \DateTimeImmutable('2025-01-01');

        $renewals = $this->upcoming->nextOccurrences(
            [$this->subscription('Leap Yearly', BillingCycle::Yearly, '2024-02-29', 99.00)],
            $today,
        );

        self::assertSame('2025-02-28', $renewals[0]->renewalDate->format('Y-m-d'));
    }

    public function testPastAnchorRollsForwardOnOrAfterToday(): void
    {
        $today = new \DateTimeImmutable('2024-06-15');

        $renewals = $this->upcoming->nextOccurrences(
            [$this->subscription('Old Monthly', BillingCycle::Monthly, '2022-01-31', 12.00)],
            $today,
        );

        self::assertSame('2024-06-30', $renewals[0]->renewalDate->format('Y-m-d'));
    }

    public function testOrdersTiesDeterministicallyByName(): void
    {
        $today = new \DateTimeImmutable('2024-03-10');

        $renewals = $this->upcoming->nextOccurrences(
            [
                $this->subscription('Zulu', BillingCycle::Monthly, '2024-03-11', 1.00),
                $this->subscription('Alpha', BillingCycle::Monthly, '2024-03-11', 2.00),
            ],
            $today,
            2,
        );

        self::assertSame(
            ['Alpha', 'Zulu'],
            array_map(fn($renewal) => $renewal->subscription->getName(), $renewals),
        );
    }

    public function testSkipsSubscriptionsWithoutAnchorOrCycle(): void
    {
        $today = new \DateTimeImmutable('2024-03-10');

        $incomplete = new Subscription();
        $incomplete->setName('Incomplete');

        $renewals = $this->upcoming->nextOccurrences(
            [
                $incomplete,
                $this->subscription('Complete', BillingCycle::Monthly, '2024-03-11', 3.00),
            ],
            $today,
        );

        self::assertNotEmpty($renewals);
        foreach ($renewals as $renewal) {
            self::assertSame('Complete', $renewal->subscription->getName());
        }
    }

    private function subscription(
        string $name,
        BillingCycle $cycle,
        string $nextPayment,
        float $amount,
    ): Subscription {
        $subscription = new Subscription();
        $subscription->setName($name);
        $subscription->setBillingCycle($cycle);
        $subscription->setNextPayment(new \DateTimeImmutable($nextPayment));
        $subscription->setAmount($amount);

        return $subscription;
    }
}
