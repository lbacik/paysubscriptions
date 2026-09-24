<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\ExpenseCategoryService;
use App\Service\SubscriptionService;
use PHPUnit\Framework\TestCase;

/**
 * Dashboard equivalents in the User's main currency (#41).
 *
 * Monthly/yearly cost equivalents normalize billing cycles (yearly ÷ 12,
 * monthly × 12); cross-currency Subscriptions contribute only their
 * user-entered converted amount — raw amounts in different currencies are
 * never added together.
 */
final class SubscriptionServiceTotalsTest extends TestCase
{
    public function testMixedCyclesNormalizeIntoBothEquivalents(): void
    {
        $service = $this->service();

        $totals = $service->getTotals([
            $this->subscription('Monthly', BillingCycle::Monthly, 10.0, 'USD'),
            $this->subscription('Yearly', BillingCycle::Yearly, 120.0, 'USD'),
        ], 'USD');

        self::assertSame('USD', $totals['currency']);
        self::assertSame(2, $totals['count']);
        self::assertSame(0, $totals['pendingReview']);
        // Direct (same-cycle) portions.
        self::assertEqualsWithDelta(10.0, $totals['monthly'], 0.001);
        self::assertEqualsWithDelta(120.0, $totals['yearly'], 0.001);
        // Normalized equivalents: 10 + 120/12 and 120 + 10*12.
        self::assertEqualsWithDelta(20.0, $totals['monthlyCalculated'], 0.001);
        self::assertEqualsWithDelta(240.0, $totals['yearlyCalculated'], 0.001);
    }

    public function testYearlyNormalizationRoundsHalfUpToCents(): void
    {
        $service = $this->service();

        $totals = $service->getTotals([
            $this->subscription('Yearly', BillingCycle::Yearly, 100.0, 'USD'),
        ], 'USD');

        // 100/12 = 8.333… → 8.33 per Subscription, then summed.
        self::assertEqualsWithDelta(8.33, $totals['monthlyCalculated'], 0.001);
        self::assertEqualsWithDelta(100.0, $totals['yearlyCalculated'], 0.001);
    }

    public function testTotalsAreRoundedToCentsWithoutFloatDust(): void
    {
        $service = $this->service();

        $totals = $service->getTotals([
            $this->subscription('First', BillingCycle::Monthly, 10.10, 'USD'),
            $this->subscription('Second', BillingCycle::Monthly, 20.20, 'USD'),
        ], 'USD');

        // 10.1 + 20.2 is 30.300000000000004 in binary floating point.
        self::assertSame(30.3, $totals['monthly']);
        self::assertSame(30.3, $totals['monthlyCalculated']);
        self::assertSame(363.6, $totals['yearlyCalculated']);
    }

    public function testCrossCurrencyContributesOnlyTheConvertedAmount(): void
    {
        $service = $this->service();

        $cross = $this->subscription('Cross', BillingCycle::Yearly, 120.0, 'EUR');
        $cross->setConvertedAmount(132.0);
        $cross->setConvertedCurrency('USD');

        $totals = $service->getTotals([
            $this->subscription('Same', BillingCycle::Monthly, 10.0, 'USD'),
            $cross,
        ], 'USD');

        // The raw EUR 120.00 must never leak into USD totals.
        self::assertEqualsWithDelta(10.0, $totals['monthly'], 0.001);
        self::assertEqualsWithDelta(132.0, $totals['yearly'], 0.001);
        self::assertEqualsWithDelta(21.0, $totals['monthlyCalculated'], 0.001);
        self::assertEqualsWithDelta(252.0, $totals['yearlyCalculated'], 0.001);
        self::assertSame(0, $totals['pendingReview']);
    }

    public function testChangingMainCurrencyInvalidatesStaleConvertedAmounts(): void
    {
        $service = $this->service();

        $cross = $this->subscription('Cross', BillingCycle::Monthly, 10.0, 'EUR');
        $cross->setConvertedAmount(11.0);
        $cross->setConvertedCurrency('USD');

        $before = $service->getTotals([$cross], 'USD');
        self::assertEqualsWithDelta(11.0, $before['monthly'], 0.001);
        self::assertSame(0, $before['pendingReview']);

        // After the User switches main currency to PLN, the USD-stamped
        // figure is stale: excluded until reviewed, never reinterpreted.
        $after = $service->getTotals([$cross], 'PLN');
        self::assertSame('PLN', $after['currency']);
        self::assertEqualsWithDelta(0.0, $after['monthly'], 0.001);
        self::assertEqualsWithDelta(0.0, $after['monthlyCalculated'], 0.001);
        self::assertSame(1, $after['pendingReview']);
    }

    public function testChangingAmountOrCycleUpdatesTotals(): void
    {
        $service = $this->service();

        $subscription = $this->subscription('Flexible', BillingCycle::Monthly, 10.0, 'USD');
        self::assertEqualsWithDelta(
            10.0,
            $service->getTotals([$subscription], 'USD')['monthlyCalculated'],
            0.001
        );

        $subscription->setAmount(20.0);
        self::assertEqualsWithDelta(
            20.0,
            $service->getTotals([$subscription], 'USD')['monthlyCalculated'],
            0.001
        );

        $subscription->setBillingCycle(BillingCycle::Yearly);
        $totals = $service->getTotals([$subscription], 'USD');
        self::assertEqualsWithDelta(0.0, $totals['monthly'], 0.001);
        self::assertEqualsWithDelta(20.0, $totals['yearly'], 0.001);
        self::assertEqualsWithDelta(1.67, $totals['monthlyCalculated'], 0.001);
        self::assertEqualsWithDelta(20.0, $totals['yearlyCalculated'], 0.001);
    }

    public function testEmptySubscriptionsRenderZeroTotalsWithCurrencyLabel(): void
    {
        $service = $this->service();

        $totals = $service->getTotals([], 'USD');

        self::assertSame('USD', $totals['currency']);
        self::assertSame(0, $totals['count']);
        self::assertSame(0, $totals['pendingReview']);
        self::assertSame(0.0, $totals['monthly']);
        self::assertSame(0.0, $totals['yearly']);
        self::assertSame(0.0, $totals['monthlyCalculated']);
        self::assertSame(0.0, $totals['yearlyCalculated']);
    }

    public function testSortingUsesConvertedAmountsInMainCurrency(): void
    {
        $cheapRaw = $this->subscription('Cheap raw', BillingCycle::Monthly, 5.0, 'EUR');
        $cheapRaw->setConvertedAmount(50.0);
        $cheapRaw->setConvertedCurrency('USD');
        $expensive = $this->subscription('Expensive', BillingCycle::Monthly, 10.0, 'USD');

        $service = $this->serviceWithSubscriptions([$cheapRaw, $expensive]);

        $owner = new User();
        $sorted = array_values($service->get($owner, 'monthly', 'asc', 'USD'));

        // By raw amounts EUR 5.00 would sort first; in USD equivalents
        // (50.00 vs 10.00) the USD Subscription is cheaper.
        self::assertSame('Expensive', $sorted[0]->getName());
        self::assertSame('Cheap raw', $sorted[1]->getName());
    }

    private function service(): SubscriptionService
    {
        return new SubscriptionService(
            $this->createMock(SubscriptionRepository::class),
            $this->createMock(UserRepository::class),
            $this->createMock(ExpenseCategoryService::class),
        );
    }

    private function serviceWithSubscriptions(array $subscriptions): SubscriptionService
    {
        $subscriptionRepository = $this->createMock(SubscriptionRepository::class);
        $subscriptionRepository->method('findBy')->willReturn($subscriptions);

        return new SubscriptionService(
            $subscriptionRepository,
            $this->createMock(UserRepository::class),
            $this->createMock(ExpenseCategoryService::class),
        );
    }

    private function subscription(string $name, BillingCycle $cycle, float $amount, ?string $currency): Subscription
    {
        $subscription = new Subscription();
        $subscription->setName($name);
        $subscription->setBillingCycle($cycle);
        $subscription->setAmount($amount);
        $subscription->setCurrency($currency);
        $subscription->setNextPayment(new \DateTime('2024-01-01'));

        return $subscription;
    }
}
