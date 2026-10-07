<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use PHPUnit\Framework\TestCase;

final class SubscriptionCurrencyTest extends TestCase
{
    public function testNewSubscriptionHasNoCurrencyByDefault(): void
    {
        $subscription = new Subscription();

        self::assertNull($subscription->getCurrency());
        self::assertNull($subscription->getConvertedAmount());
        self::assertNull($subscription->getConvertedCurrency());
    }

    public function testLegacyUserHasNoMainCurrencyByDefault(): void
    {
        $user = new User();

        self::assertNull($user->getMainCurrency());
    }

    public function testMainCurrencyIsNormalizedToUppercase(): void
    {
        $user = new User();
        $user->setMainCurrency('usd');

        self::assertSame('USD', $user->getMainCurrency());
    }

    public function testInvalidMainCurrencyIsRejected(): void
    {
        $user = new User();

        $this->expectException(\InvalidArgumentException::class);
        $user->setMainCurrency('XX1');
    }

    public function testCurrencyIsRequiredOnceMainCurrencyIsConfirmed(): void
    {
        $subscription = $this->monthlySubscription(null, 10.0);

        self::assertNotSame([], $subscription->validateConverted('USD'));
    }

    public function testSameCurrencySubscriptionNeedsNoConvertedAmount(): void
    {
        $subscription = $this->monthlySubscription('USD', 10.0);

        self::assertFalse($subscription->isCrossCurrency('USD'));
        self::assertSame([], $subscription->validateConverted('USD'));
        self::assertSame(10.0, $subscription->getReportingAmount('USD'));
        self::assertSame(120.0, $subscription->getReportingYearlyCalculated('USD'));
    }

    public function testSameCurrencySubscriptionMustNotDuplicateConvertedInput(): void
    {
        $subscription = $this->monthlySubscription('USD', 10.0);
        $subscription->setConvertedAmount(10.0);
        $subscription->setConvertedCurrency('USD');

        self::assertNotSame([], $subscription->validateConverted('USD'));
    }

    public function testCrossCurrencySubscriptionRequiresPositiveConvertedAmount(): void
    {
        $subscription = $this->monthlySubscription('EUR', 10.0);

        self::assertTrue($subscription->isCrossCurrency('USD'));
        self::assertNotSame([], $subscription->validateConverted('USD'));

        $subscription->setConvertedAmount(11.0);
        $subscription->setConvertedCurrency('USD');

        self::assertSame([], $subscription->validateConverted('USD'));
        self::assertSame(11.0, $subscription->getReportingAmount('USD'));
    }

    public function testCrossCurrencyConvertedAmountMustBePositive(): void
    {
        foreach ([0.0, -5.0] as $amount) {
            $subscription = $this->monthlySubscription('EUR', 10.0);
            $subscription->setConvertedAmount($amount);
            $subscription->setConvertedCurrency('USD');

            self::assertNotSame([], $subscription->validateConverted('USD'), 'amount '.$amount.' should be rejected');
        }
    }

    public function testYearlyCrossCurrencyUsesConvertedAmount(): void
    {
        $subscription = new Subscription();
        $subscription->setName('Yearly Service');
        $subscription->setNextPayment(new \DateTime('2024-01-01'));
        $subscription->setBillingCycle(BillingCycle::Yearly);
        $subscription->setAmount(120.0);
        $subscription->setCurrency('EUR');
        $subscription->setConvertedAmount(132.0);
        $subscription->setConvertedCurrency('USD');

        self::assertSame([], $subscription->validateConverted('USD'));
        self::assertSame(132.0, $subscription->getReportingAmount('USD'));
        self::assertSame(11.0, $subscription->getReportingMonthlyCalculated('USD'));
    }

    public function testFreeTextCurrencyCodesAreRejected(): void
    {
        $subscription = $this->monthlySubscription('XX1', 10.0);

        self::assertNotSame([], $subscription->validateConverted('USD'));
    }

    public function testLegacySubscriptionWithoutCurrencyPassesValidationUnchanged(): void
    {
        $subscription = $this->monthlySubscription(null, 10.0);

        self::assertFalse($subscription->isCrossCurrency(null));
        self::assertSame([], $subscription->validateConverted(null));
        self::assertFalse($subscription->needsConvertedReview(null));
        self::assertSame(10.0, $subscription->getReportingAmount(null));
    }

    public function testLegacyUserWithoutMainCurrencyImposesNoConvertedRequirement(): void
    {
        $subscription = $this->monthlySubscription('EUR', 10.0);

        self::assertFalse($subscription->isCrossCurrency(null));
        self::assertSame([], $subscription->validateConverted(null));
    }

    public function testConvertedAmountBecomesStaleAfterMainCurrencyChange(): void
    {
        $subscription = $this->monthlySubscription('EUR', 10.0);
        $subscription->setConvertedAmount(11.0);
        $subscription->setConvertedCurrency('USD');

        self::assertFalse($subscription->needsConvertedReview('USD'));
        self::assertTrue($subscription->needsConvertedReview('PLN'));
        self::assertNotSame([], $subscription->validateConverted('PLN'));
    }

    public function testCrossCurrencyWithoutConvertedAmountIsPendingReview(): void
    {
        $subscription = $this->monthlySubscription('EUR', 10.0);

        self::assertTrue($subscription->isPendingReview('USD'));
        self::assertFalse($subscription->isPendingReview(null));
        self::assertFalse($this->monthlySubscription('USD', 10.0)->isPendingReview('USD'));
        self::assertFalse($this->monthlySubscription(null, 10.0)->isPendingReview('USD'));
    }

    public function testStaleConvertedAmountStaysSaveableUntilReviewed(): void
    {
        $subscription = $this->monthlySubscription('EUR', 10.0);
        $subscription->setConvertedAmount(11.0);
        $subscription->setConvertedCurrency('USD');

        // Main currency changed: the stale stamp is preserved, never
        // silently reinterpreted, and unrelated saves stay possible.
        $subscription->syncConvertedCurrency('PLN');

        self::assertSame('USD', $subscription->getConvertedCurrency());
        self::assertSame([], $subscription->validateConverted('PLN', true));
        self::assertNotSame([], $subscription->validateConverted('PLN'));
    }

    public function testSyncConvertedCurrencyClearsDuplicateInputForSameCurrency(): void
    {
        $subscription = $this->monthlySubscription('EUR', 10.0);
        $subscription->setConvertedAmount(11.0);
        $subscription->setConvertedCurrency('USD');

        $subscription->setCurrency('USD');
        $subscription->syncConvertedCurrency('USD');

        self::assertNull($subscription->getConvertedAmount());
        self::assertNull($subscription->getConvertedCurrency());
        self::assertSame([], $subscription->validateConverted('USD'));
    }

    public function testSyncConvertedCurrencyStampsCurrentMainCurrency(): void
    {
        $subscription = $this->monthlySubscription('EUR', 10.0);
        $subscription->setConvertedAmount(11.0);

        $subscription->syncConvertedCurrency('USD');

        self::assertSame('USD', $subscription->getConvertedCurrency());
        self::assertSame([], $subscription->validateConverted('USD'));
    }

    public function testReconcileConvertedStampsReEnteredAmount(): void
    {
        $subscription = $this->monthlySubscription('EUR', 10.0);
        $subscription->setConvertedAmount(11.0);
        $subscription->setConvertedCurrency('USD');

        $subscription->setConvertedAmount(12.0);
        $subscription->reconcileConverted('EUR', 11.0, 'USD');

        self::assertSame('USD', $subscription->getConvertedCurrency());
        self::assertSame([], $subscription->validateConverted('USD', true));
    }

    public function testReconcileConvertedClearsFigureWhenCurrencyChanged(): void
    {
        $subscription = $this->monthlySubscription('EUR', 10.0);
        $subscription->setConvertedAmount(11.0);
        $subscription->setConvertedCurrency('USD');

        // Currency changed EUR -> GBP but the old figure was kept: it was
        // computed for another currency, so it must be re-entered.
        $subscription->setCurrency('GBP');
        $subscription->reconcileConverted('EUR', 11.0, 'USD');

        self::assertNull($subscription->getConvertedAmount());
        self::assertNull($subscription->getConvertedCurrency());
        self::assertTrue($subscription->isPendingReview('USD'));
    }

    public function testReconcileConvertedKeepsUntouchedStaleStamp(): void
    {
        $subscription = $this->monthlySubscription('EUR', 10.0);
        $subscription->setConvertedAmount(11.0);
        $subscription->setConvertedCurrency('USD');

        $subscription->reconcileConverted('EUR', 11.0, 'PLN');

        self::assertSame(11.0, $subscription->getConvertedAmount());
        self::assertSame('USD', $subscription->getConvertedCurrency());
        self::assertTrue($subscription->isPendingReview('PLN'));
    }

    public function testGetConvertedDisplayAmountReturnsConvertedAmountOnlyWhenReportingUsesIt(): void
    {
        // Same currency: no converted line.
        self::assertNull($this->monthlySubscription('USD', 10.0)->getConvertedDisplayAmount('USD'));

        // Cross-currency, fresh, with a converted amount: show it.
        $fresh = $this->monthlySubscription('EUR', 10.0);
        $fresh->setConvertedAmount(11.0);
        $fresh->setConvertedCurrency('USD');
        self::assertSame(11.0, $fresh->getConvertedDisplayAmount('USD'));

        // Stale converted amount (entered against a previous main currency):
        // pending review, so no converted line.
        $stale = $this->monthlySubscription('EUR', 10.0);
        $stale->setConvertedAmount(11.0);
        $stale->setConvertedCurrency('USD');
        self::assertNull($stale->getConvertedDisplayAmount('PLN'));

        // Cross-currency without any converted amount: pending review, no line.
        self::assertNull($this->monthlySubscription('EUR', 10.0)->getConvertedDisplayAmount('USD'));

        // No main currency: never cross-currency, no line.
        $noMain = $this->monthlySubscription('EUR', 10.0);
        $noMain->setConvertedAmount(11.0);
        $noMain->setConvertedCurrency('USD');
        self::assertNull($noMain->getConvertedDisplayAmount(null));
        self::assertNull($this->monthlySubscription(null, 10.0)->getConvertedDisplayAmount(null));
    }

    public function testGetConvertedDisplayAmountStaysConsistentWithReportingAmount(): void
    {
        $fresh = $this->monthlySubscription('EUR', 10.0);
        $fresh->setConvertedAmount(11.0);
        $fresh->setConvertedCurrency('USD');

        self::assertSame($fresh->getConvertedDisplayAmount('USD'), $fresh->getReportingAmount('USD'));

        $same = $this->monthlySubscription('USD', 10.0);
        self::assertNull($same->getConvertedDisplayAmount('USD'));
        self::assertSame(10.0, $same->getReportingAmount('USD'));
    }

    private function monthlySubscription(?string $currency, float $amount): Subscription
    {
        $subscription = new Subscription();
        $subscription->setName('Test Service');
        $subscription->setNextPayment(new \DateTime('2024-01-01'));
        $subscription->setBillingCycle(BillingCycle::Monthly);
        $subscription->setAmount($amount);
        $subscription->setCurrency($currency);

        return $subscription;
    }
}
