<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ExpenseCategory;
use App\Entity\Subscription;
use App\Entity\User;
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

    public function testAddDefaultsSubscriptionCurrencyToMainCurrency(): void
    {
        $owner = new User();
        $owner->setEmail('owner@example.com');
        $owner->setMainCurrency('USD');

        $subscription = $this->monthlySubscription('Defaulted', null, 10.0);
        $subscription->setOwner($owner);
        $subscription->setCategory($this->ownedCategory($owner));

        $this->serviceWithPersistedUser($owner)->add($subscription);

        self::assertSame('USD', $subscription->getCurrency());
        self::assertNull($subscription->getConvertedAmount());
    }

    public function testAddStampsFreshCrossCurrencyAmountWithMainCurrency(): void
    {
        $owner = new User();
        $owner->setEmail('owner@example.com');
        $owner->setMainCurrency('USD');

        $subscription = $this->monthlySubscription('Cross', 'EUR', 10.0);
        $subscription->setConvertedAmount(11.0);
        $subscription->setOwner($owner);
        $subscription->setCategory($this->ownedCategory($owner));

        $this->serviceWithPersistedUser($owner)->add($subscription);

        self::assertSame('EUR', $subscription->getCurrency());
        self::assertSame('USD', $subscription->getConvertedCurrency());
    }

    public function testAddRejectsCrossCurrencySubscriptionWithoutConvertedAmount(): void
    {
        $owner = new User();
        $owner->setEmail('owner@example.com');
        $owner->setMainCurrency('USD');

        $subscription = $this->monthlySubscription('Missing', 'EUR', 10.0);
        $subscription->setOwner($owner);
        $subscription->setCategory($this->ownedCategory($owner));

        $this->expectException(\InvalidArgumentException::class);
        $this->serviceWithPersistedUser($owner)->add($subscription);
    }

    public function testAddLeavesLegacySubscriptionWithoutCurrenciesUnchanged(): void
    {
        $owner = new User();
        $owner->setEmail('owner@example.com');

        $subscription = $this->monthlySubscription('Legacy', null, 10.0);
        $subscription->setOwner($owner);
        $subscription->setCategory($this->ownedCategory($owner));

        $this->serviceWithPersistedUser($owner)->add($subscription);

        self::assertNull($subscription->getCurrency());
        self::assertNull($subscription->getConvertedAmount());
    }

    private function service(): SubscriptionService
    {
        return new SubscriptionService(
            $this->createMock(SubscriptionRepository::class),
            $this->createMock(UserRepository::class),
            $this->createMock(ExpenseCategoryService::class),
        );
    }

    private function serviceWithPersistedUser(User $persisted): SubscriptionService
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('find')->willReturn($persisted);

        return new SubscriptionService(
            $this->createMock(SubscriptionRepository::class),
            $userRepository,
            $this->createMock(ExpenseCategoryService::class),
        );
    }

    private function ownedCategory(User $owner): ExpenseCategory
    {
        $category = (new ExpenseCategory())
            ->setName(ExpenseCategory::DEFAULT_NAME)
            ->setColor(ExpenseCategory::DEFAULT_COLOR);
        $owner->addExpenseCategory($category);

        return $category;
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
