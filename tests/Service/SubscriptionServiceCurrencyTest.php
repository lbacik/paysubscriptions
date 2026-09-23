<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Subscription;
use App\Enum\BillingCycle;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\ExpenseCategoryService;
use App\Service\SubscriptionService;
use PHPUnit\Framework\TestCase;

final class SubscriptionServiceCurrencyTest extends TestCase
{
    public function testTotalsUseConvertedAmountsForCrossCurrencySubscriptions(): void
    {
        $service = $this->service();

        $same = $this->monthlySubscription('Same', 'USD', 10.0);
        $cross = $this->monthlySubscription('Cross', 'EUR', 10.0);
        $cross->setConvertedAmount(11.0);
        $cross->setConvertedCurrency('USD');

        $totals = $service->getTotals([$same, $cross], 'USD');

        self::assertSame('USD', $totals['currency']);
        self::assertEqualsWithDelta(21.0, $totals['monthly'], 0.001);
        self::assertEqualsWithDelta(21.0, $totals['monthlyCalculated'], 0.001);
        self::assertEqualsWithDelta(252.0, $totals['yearlyCalculated'], 0.001);
        self::assertSame(0, $totals['pendingReview']);
        self::assertSame(2, $totals['count']);
    }

    public function testStaleConvertedAmountsAreExcludedFromTotalsPendingReview(): void
    {
        $service = $this->service();

        $fresh = $this->monthlySubscription('Fresh', 'EUR', 10.0);
        $fresh->setConvertedAmount(11.0);
        $fresh->setConvertedCurrency('USD');

        $stale = $this->monthlySubscription('Stale', 'EUR', 20.0);
        $stale->setConvertedAmount(22.0);
        $stale->setConvertedCurrency('PLN'); // entered against a previous main currency

        $totals = $service->getTotals([$fresh, $stale], 'USD');

        self::assertEqualsWithDelta(11.0, $totals['monthly'], 0.001);
        self::assertSame(1, $totals['pendingReview']);

        $staleList = $service->getPendingReviewSubscriptions([$fresh, $stale], 'USD');
        self::assertSame([$stale], $staleList);
    }

    public function testCrossCurrencyWithoutConvertedAmountIsPendingReview(): void
    {
        $service = $this->service();

        // Saved before the main currency was confirmed: no converted amount.
        $missing = $this->monthlySubscription('Missing', 'EUR', 10.0);

        $totals = $service->getTotals([$missing], 'USD');

        self::assertEqualsWithDelta(0.0, $totals['monthly'], 0.001);
        self::assertSame(1, $totals['pendingReview']);
        self::assertSame([$missing], $service->getPendingReviewSubscriptions([$missing], 'USD'));
    }

    public function testLegacyDataWithoutCurrenciesKeepsRawTotalsUnlabelled(): void
    {
        $service = $this->service();

        $legacy = $this->monthlySubscription('Legacy', null, 10.0);

        $totals = $service->getTotals([$legacy]);

        self::assertNull($totals['currency']);
        self::assertEqualsWithDelta(10.0, $totals['monthly'], 0.001);
        self::assertSame(0, $totals['pendingReview']);
    }

    public function testLegacyTotalsStayRawUntilMainCurrencyIsConfirmed(): void
    {
        $service = $this->service();

        $legacy = $this->monthlySubscription('Legacy', null, 10.0);

        // Main currency confirmed later: legacy amounts are still reported unchanged.
        $totals = $service->getTotals([$legacy], 'USD');

        self::assertSame('USD', $totals['currency']);
        self::assertEqualsWithDelta(10.0, $totals['monthly'], 0.001);
        self::assertSame(0, $totals['pendingReview']);
    }

    private function service(): SubscriptionService
    {
        return new SubscriptionService(
            $this->createMock(SubscriptionRepository::class),
            $this->createMock(UserRepository::class),
            $this->createMock(ExpenseCategoryService::class),
        );
    }

    private function monthlySubscription(string $name, ?string $currency, float $amount): Subscription
    {
        $subscription = new Subscription();
        $subscription->setName($name);
        $subscription->setNextPayment(new \DateTime('2024-01-01'));
        $subscription->setBillingCycle(BillingCycle::Monthly);
        $subscription->setAmount($amount);
        $subscription->setCurrency($currency);

        return $subscription;
    }
}
