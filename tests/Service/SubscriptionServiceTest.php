<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use App\Service\SubscriptionService;
use App\Tests\DatabaseTestCase;

/**
 * SubscriptionService owns the per-user listing and the subscription-limit
 * gate. All listing behavior is scoped to the given owner: another user's
 * rows must never appear.
 */
final class SubscriptionServiceTest extends DatabaseTestCase
{
    private const PASSWORD = 'Fixture-Password-1';

    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = static::getContainer()->get(SubscriptionService::class);
    }

    public function testListingIsScopedToTheOwner(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $bob = $this->createUser('bob@example.com', self::PASSWORD, true);
        $this->createSubscription($alice, 'Alice Sub', BillingCycle::Monthly, 10.0);
        $this->createSubscription($bob, 'Bob Sub', BillingCycle::Monthly, 20.0);

        $aliceSubs = $this->service->get($alice, 'name', 'asc');
        $bobSubs = $this->service->get($bob, 'name', 'asc');

        self::assertSame(['Alice Sub'], $this->names($aliceSubs));
        self::assertSame(['Bob Sub'], $this->names($bobSubs));
    }

    public function testListingSortsByName(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $this->createSubscription($alice, 'Zulu', BillingCycle::Monthly, 10.0);
        $this->createSubscription($alice, 'Alpha', BillingCycle::Monthly, 10.0);

        self::assertSame(['Alpha', 'Zulu'], $this->names($this->service->get($alice, 'name', 'asc')));
        self::assertSame(['Zulu', 'Alpha'], $this->names($this->service->get($alice, 'name', 'desc')));
    }

    public function testListingSortsByCalculatedAmounts(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        // Monthly equivalents: Cheap 5, Mid 120/12=10, Pricey 30.
        $this->createSubscription($alice, 'Pricey', BillingCycle::Monthly, 30.0);
        $this->createSubscription($alice, 'Mid', BillingCycle::Yearly, 120.0);
        $this->createSubscription($alice, 'Cheap', BillingCycle::Monthly, 5.0);

        self::assertSame(
            ['Cheap', 'Mid', 'Pricey'],
            $this->names($this->service->get($alice, 'monthly', 'asc')),
        );
        // Yearly equivalents: Mid 120, Pricey 360, Cheap 60.
        self::assertSame(
            ['Pricey', 'Mid', 'Cheap'],
            $this->names($this->service->get($alice, 'yearly', 'desc')),
        );
    }

    public function testAddPersistsAndCountsAgainstTheLimit(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $alice->setSubscriptionsLimit(2);
        $this->em->flush();

        // Each add runs on a freshly loaded user, the way separate HTTP
        // requests each boot a fresh entity manager: the limit check reads
        // committed rows, not the previous call's in-memory collection.
        $this->service->add($this->candidateFor($alice, 'First'));
        $alice = $this->refetchUser($alice);
        $this->service->add($this->candidateFor($alice, 'Second'));
        $alice = $this->refetchUser($alice);

        self::assertTrue($this->freshUserLimitReached($alice));
        self::assertFalse($this->service->isAbleToAddSubscription($alice));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('maximum number of subscriptions');
        $this->service->add($this->candidateFor($alice, 'Third'));
    }

    public function testDefaultLimitAllowsThirtySubscriptions(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);

        self::assertSame(30, $alice->getSubscriptionsLimit());
        self::assertTrue($this->service->isAbleToAddSubscription($alice));
    }

    public function testLimitsArePerUser(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $alice->setSubscriptionsLimit(1);
        $bob = $this->createUser('bob@example.com', self::PASSWORD, true);
        $this->em->flush();

        $this->service->add($this->candidateFor($alice, 'Alice Only'));
        $alice = $this->refetchUser($alice);
        $bob = $this->refetchUser($bob);

        // Alice is full, bob is unaffected.
        self::assertFalse($this->service->isAbleToAddSubscription($alice));
        self::assertTrue($this->service->isAbleToAddSubscription($bob));
    }

    public function testUpdatePersistsChanges(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $subscription = $this->createSubscription($alice, 'Netflix', BillingCycle::Monthly, 15.99);

        $subscription->setAmount(22.99);
        $this->service->update($subscription);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Subscription::class)->find($subscription->getId());
        self::assertSame(22.99, $reloaded->getAmount());
    }

    /**
     * A new entry exactly as SubscriptionController::new() builds it: the
     * owner is set on the owning side only, so the candidate never pollutes
     * the owner's in-memory collection before the limit check runs.
     */
    private function candidateFor(User $owner, string $name): Subscription
    {
        return (new Subscription())
            ->setOwner($owner)
            ->setName($name)
            ->setBillingCycle(BillingCycle::Monthly)
            ->setAmount(10.0)
            ->setNextPayment(new \DateTime('2024-01-15'));
    }

    /**
     * @param array<Subscription> $subscriptions
     *
     * @return list<string>
     */
    private function names(array $subscriptions): array
    {
        return array_values(array_map(fn(Subscription $s) => $s->getName(), $subscriptions));
    }

    private function freshUserLimitReached(User $alice): bool
    {
        $fresh = $this->refetchUser($alice);

        return count($fresh->getSubscriptions()) >= $fresh->getSubscriptionsLimit();
    }

    private function refetchUser(User $user): User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->find($user->getId());
    }
}
