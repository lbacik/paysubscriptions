<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ExpenseCategory;
use App\Entity\Subscription;
use App\Enum\BillingCycle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SubscriptionTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testEmptySubscriptionViolatesCycleAmountNextPaymentAndCategory(): void
    {
        $violations = $this->validator->validate(new Subscription());

        $paths = array_map(
            fn($violation) => $violation->getPropertyPath(),
            iterator_to_array($violations),
        );

        self::assertContains('billingCycle', $paths);
        self::assertContains('amount', $paths);
        self::assertContains('nextPayment', $paths);
        self::assertContains('category', $paths);
    }

    public function testSubscriptionWithCycleAmountAndNextPaymentIsValid(): void
    {
        $subscription = (new Subscription())
            ->setName('Netflix')
            ->setBillingCycle(BillingCycle::Monthly)
            ->setAmount(15.99)
            ->setNextPayment(new \DateTimeImmutable('2024-02-15'))
            ->setCategory(new ExpenseCategory());

        self::assertCount(0, $this->validator->validate($subscription));
    }

    public function testMonthlyCycleNormalizesYearlyTotal(): void
    {
        $subscription = (new Subscription())
            ->setBillingCycle(BillingCycle::Monthly)
            ->setAmount(10.00);

        self::assertSame(10.00, $subscription->getMonthlyCalculated());
        self::assertSame(120.00, $subscription->getYearlyCalculated());
    }

    public function testYearlyCycleNormalizesMonthlyTotal(): void
    {
        $subscription = (new Subscription())
            ->setBillingCycle(BillingCycle::Yearly)
            ->setAmount(120.00);

        self::assertSame(10.00, $subscription->getMonthlyCalculated());
        self::assertSame(120.00, $subscription->getYearlyCalculated());
    }

    public function testMonthlyCalculatedThrowsWithoutAmount(): void
    {
        $subscription = (new Subscription())->setBillingCycle(BillingCycle::Monthly);

        $this->expectException(\LogicException::class);

        $subscription->getMonthlyCalculated();
    }

    public function testYearlyCalculatedThrowsWithoutBillingCycle(): void
    {
        $subscription = (new Subscription())->setAmount(10.00);

        $this->expectException(\LogicException::class);

        $subscription->getYearlyCalculated();
    }

    public function testNotesAreOptional(): void
    {
        $subscription = (new Subscription())
            ->setName('Netflix')
            ->setBillingCycle(BillingCycle::Monthly)
            ->setAmount(15.99)
            ->setNextPayment(new \DateTimeImmutable('2024-02-15'))
            ->setCategory(new ExpenseCategory());

        self::assertNull($subscription->getNotes());
        self::assertCount(0, $this->validator->validate($subscription));
    }

    public function testBlankNotesNormalizeToNull(): void
    {
        $subscription = (new Subscription())->setNotes('   ');

        self::assertNull($subscription->getNotes());
    }

    public function testNotesExceedingMaxLengthViolate(): void
    {
        $subscription = (new Subscription())->setNotes(str_repeat('a', 2001));

        $violations = $this->validator->validate($subscription);

        $paths = array_map(
            fn($violation) => $violation->getPropertyPath(),
            iterator_to_array($violations),
        );

        self::assertContains('notes', $paths);
    }
}
