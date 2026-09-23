<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Subscription;
use PHPUnit\Framework\TestCase;

/**
 * Subscription is valid with exactly one of monthly/yearly set, and converts
 * between the two on read (the "renewal and currency" math the dashboard and
 * charts are built on: monthly = yearly/12, yearly = monthly*12).
 */
final class SubscriptionTest extends TestCase
{
    public function testMonthlyOnlyIsValid(): void
    {
        $subscription = $this->subscription(monthly: 15.99);

        self::assertTrue($subscription->isValid());
    }

    public function testYearlyOnlyIsValid(): void
    {
        $subscription = $this->subscription(yearly: 139.0);

        self::assertTrue($subscription->isValid());
    }

    public function testBothAmountsAreInvalid(): void
    {
        $subscription = $this->subscription(monthly: 15.99, yearly: 139.0);

        self::assertFalse($subscription->isValid());
    }

    public function testNeitherAmountIsInvalid(): void
    {
        $subscription = $this->subscription();

        self::assertFalse($subscription->isValid());
    }

    public function testMonthlyCalculatedNormalizesYearly(): void
    {
        $subscription = $this->subscription(yearly: 120.0);

        // 120/12, rounded to cents.
        self::assertSame(10.0, $subscription->getMonthlyCalculated());
    }

    public function testMonthlyCalculatedRoundsToCents(): void
    {
        $subscription = $this->subscription(yearly: 100.0);

        self::assertSame(8.33, $subscription->getMonthlyCalculated());
    }

    public function testMonthlyCalculatedPrefersMonthly(): void
    {
        $subscription = $this->subscription(monthly: 15.99);

        self::assertSame(15.99, $subscription->getMonthlyCalculated());
    }

    public function testYearlyCalculatedNormalizesMonthly(): void
    {
        $subscription = $this->subscription(monthly: 15.99);

        self::assertSame(191.88, $subscription->getYearlyCalculated());
    }

    public function testYearlyCalculatedPrefersYearly(): void
    {
        $subscription = $this->subscription(yearly: 139.0);

        self::assertSame(139.0, $subscription->getYearlyCalculated());
    }

    private function subscription(?float $monthly = null, ?float $yearly = null): Subscription
    {
        return (new Subscription())
            ->setName('Netflix')
            ->setFirstPayment(new \DateTime('2024-01-15'))
            ->setMonthly($monthly)
            ->setYearly($yearly);
    }
}
