<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Subscription;
use App\Entity\User;
use App\Service\SubscriptionService;
use App\Tests\DatabaseTestCase;

/**
 * SubscriptionService owns the per-user listing, the totals arithmetic and
 * the subscription-limit gate. All listing behavior is scoped to the given
 * owner: another user's rows must never appear.
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

    public function testTotalsCombineDirectAndNormalizedAmounts(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $subs = [
            $this->createSubscription($alice, 'Netflix', 15.99, null),
            $this->createSubscription($alice, 'Amazon Prime', null, 120.0),
        ];

        $totals = $this->service->getTotals($subs);

        self::assertSame(15.99, $totals['monthly']);
        self::assertSame(120.0, $totals['yearly']);
        // 15.99 + 120/12.
        self::assertEqualsWithDelta(25.99, $totals['monthlyCalculated'], 0.001);
        // 120 + 15.99*12.
        self::assertEqualsWithDelta(311.88, $totals['yearlyCalculated'], 0.001);
        self::assertSame(2, $totals['count']);
    }

    public function testTotalsOfAnEmptyListAreZero(): void
    {
        $totals = $this->service->getTotals([]);

        self::assertSame(
            ['monthly' => 0.0, 'yearly' => 0.0, 'monthlyCalculated' => 0.0, 'yearlyCalculated' => 0.0, 'count' => 0],
            $totals,
        );
    }

    public function testListingIsScopedToTheOwner(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $bob = $this->createUser('bob@example.com', self::PASSWORD, true);
        $this->createSubscription($alice, 'Alice Sub', 10.0, null);
        $this->createSubscription($bob, 'Bob Sub', 20.0, null);

        $aliceSubs = $this->service->get($alice, 'name', 'asc');
        $bobSubs = $this->service->get($bob, 'name', 'asc');

        self::assertSame(['Alice Sub'], $this->names($aliceSubs));
        self::assertSame(['Bob Sub'], $this->names($bobSubs));
    }

    public function testListingSortsByName(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $this->createSubscription($alice, 'Zulu', 10.0, null);
        $this->createSubscription($alice, 'Alpha', 10.0, null);

        self::assertSame(['Alpha', 'Zulu'], $this->names($this->service->get($alice, 'name', 'asc')));
        self::assertSame(['Zulu', 'Alpha'], $this->names($this->service->get($alice, 'name', 'desc')));
    }

    public function testListingSortsByCalculatedAmounts(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        // Monthly equivalents: Cheap 5, Mid 120/12=10, Pricey 30.
        $this->createSubscription($alice, 'Pricey', 30.0, null);
        $this->createSubscription($alice, 'Mid', null, 120.0);
        $this->createSubscription($alice, 'Cheap', 5.0, null);

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
        $subscription = $this->createSubscription($alice, 'Netflix', 15.99, null);

        $subscription->setMonthly(22.99);
        $this->service->update($subscription);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Subscription::class)->find($subscription->getId());
        self::assertSame(22.99, $reloaded->getMonthly());
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
            ->setFirstPayment(new \DateTime('2024-01-15'))
            ->setMonthly(10.0);
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
