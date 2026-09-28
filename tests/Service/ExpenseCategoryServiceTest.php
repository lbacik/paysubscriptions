<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ExpenseCategory;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use App\Exception\DuplicateCategoryNameException;
use App\Repository\ExpenseCategoryRepository;
use App\Service\ExpenseCategoryService;
use App\Service\SubscriptionService;
use App\Tests\DatabaseTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Needs a database: set DATABASE_URL (e.g. the CI migrations job's
 * mysql://root:root@127.0.0.1:3306/paysub) before running.
 */
final class ExpenseCategoryServiceTest extends DatabaseTestCase
{
    private ExpenseCategoryService $categories;
    private SubscriptionService $subscriptions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categories = static::getContainer()->get(ExpenseCategoryService::class);
        $this->subscriptions = static::getContainer()->get(SubscriptionService::class);
    }

    private function createSubscriptionWithCategory(User $owner, ?ExpenseCategory $category = null, string $name = 'Netflix'): Subscription
    {
        $subscription = (new Subscription())
            ->setName($name)
            ->setBillingCycle(BillingCycle::Monthly)
            ->setAmount(15.99)
            ->setNextPayment(new \DateTimeImmutable('2024-01-15'))
            ->setOwner($owner);
        if (null !== $category) {
            $subscription->setCategory($category);
        }

        $this->subscriptions->add($subscription);

        return $subscription;
    }

    public function testEnsureDefaultCategorySeedsOneEditableCategory(): void
    {
        $user = $this->createUser('fresh@example.com');

        $default = $this->categories->ensureDefaultCategory($user);

        self::assertSame(ExpenseCategory::DEFAULT_NAME, $default->getName());
        self::assertSame(ExpenseCategory::DEFAULT_COLOR, $default->getColor());
        self::assertTrue($default->isOwnedBy($user));

        // Second call must not create a duplicate.
        $this->categories->ensureDefaultCategory($user);
        self::assertCount(1, $this->categories->getForOwner($user));

        // The seeded category stays editable: rename and recolor work.
        $this->categories->rename($default, 'Streaming');
        $this->categories->recolor($default, '#fe5f55');
        self::assertSame('Streaming', $default->getName());
        self::assertSame('#fe5f55', $default->getColor());
    }

    public function testEnsureDefaultCategoryLoserSeesWinnerCommittedMidRequest(): void
    {
        $user = $this->createUser('racer@example.com');
        // An unrelated unflushed change, proving the unit of work stays
        // intact: the old recovery called EntityManager::clear() here, and a
        // post-violation recovery cannot work at all (a failed flush closes
        // the manager), so the race must be serialized, never recovered.
        $user->setTimezone('Europe/Paris');

        // The concurrent winner, committed before this request runs.
        $winner = $this->categories->create($user, ExpenseCategory::DEFAULT_NAME, ExpenseCategory::DEFAULT_COLOR);

        // Simulates the loser's pre-check running before the winner commit:
        // the first two lookups (the read-only pre-check) see nothing, the
        // in-transaction re-check under the owner lock sees the winner.
        $repository = new class(static::getContainer()->get('doctrine')) extends ExpenseCategoryRepository {
            public int $findCalls = 0;

            public function findOneBy(array $criteria, ?array $orderBy = null): ?object
            {
                if (++$this->findCalls <= 2) {
                    return null;
                }

                return parent::findOneBy($criteria, $orderBy);
            }
        };

        $service = new ExpenseCategoryService($repository, $this->em);
        $default = $service->ensureDefaultCategory($user);

        self::assertSame($winner->getId()->toString(), $default->getId()->toString());

        // Exactly one row: no duplicate was inserted.
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM expense_category WHERE owner_id = ?',
            [$user->getId()->toBinary()]
        ));

        // The manager stayed open with the owner (and its pending change)
        // intact, so the surrounding write (e.g. the subscription being
        // added) can still flush.
        self::assertTrue($this->em->isOpen());
        self::assertTrue($this->em->contains($user));
        $this->em->flush();
        self::assertSame('Europe/Paris', $this->freshUser('racer@example.com')?->getTimezone());
    }

    public function testCreateRecheckThrowsDomainExceptionWithManagerIntact(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->categories->create($user, 'Food', '#ff0000');

        // The in-transaction re-check (under the owner lock) reports the
        // taken name without ever attempting the INSERT, so unlike a
        // post-violation recovery the manager stays open and usable.
        try {
            $this->categories->create($user, 'Food', '#00ff00');
            self::fail('Creating a taken name must throw.');
        } catch (DuplicateCategoryNameException $exception) {
            self::assertStringContainsString('Food', $exception->getMessage());
        }

        self::assertTrue($this->em->isOpen());
        self::assertTrue($this->em->contains($user));
        self::assertCount(1, $user->getExpenseCategories());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM expense_category WHERE owner_id = ? AND name = ?',
            [$user->getId()->toBinary(), 'Food']
        ));
    }

    public function testCreateViolationTranslatesToDomainException(): void
    {
        $user = $this->createUser('owner@example.com');

        // Simulates the loser's in-transaction check running before the
        // winner's commit: the check sees nothing, the INSERT then hits a
        // genuine unique-constraint violation, which must surface as the
        // domain exception (last-resort translation, not a 500).
        $repository = new class(static::getContainer()->get('doctrine')) extends ExpenseCategoryRepository {
            public int $findCalls = 0;

            public function findOneBy(array $criteria, ?array $orderBy = null): ?object
            {
                if (++$this->findCalls <= 1) {
                    return null;
                }

                return parent::findOneBy($criteria, $orderBy);
            }

            public function save(ExpenseCategory $category): void
            {
                if (1 === $this->findCalls) {
                    $this->getEntityManager()->getConnection()->insert('expense_category', [
                        'id' => Uuid::v7()->toBinary(),
                        'owner_id' => $category->getOwner()?->getId()?->toBinary(),
                        'name' => $category->getName(),
                        'color' => $category->getColor(),
                        'created_at' => '2024-01-01 00:00:00',
                        'updated_at' => '2024-01-01 00:00:00',
                    ]);
                }

                parent::save($category);
            }
        };

        $service = new ExpenseCategoryService($repository, $this->em);

        try {
            $service->create($user, 'Food', '#ff0000');
            self::fail('A lost creation race must throw.');
        } catch (DuplicateCategoryNameException $exception) {
            self::assertStringContainsString('Food', $exception->getMessage());
            self::assertNotNull($exception->getPrevious());
        }

        // The simulated winner was inserted on the same test-transaction
        // connection, so the service-transaction rollback undoes it too (in
        // production the winner committed in another connection and
        // survives): the point here is the exception translation, which the
        // re-check test above covers for row counts.
    }

    public function testCreateRenameRecolor(): void
    {
        $user = $this->createUser('owner@example.com');

        $category = $this->categories->create($user, 'Food', '#ff0000');
        self::assertSame('Food', $category->getName());
        self::assertSame('#ff0000', $category->getColor());

        $this->categories->rename($category, 'Groceries');
        $this->categories->recolor($category, '#00ff00');

        $this->em->clear();
        $reloaded = $this->em->getRepository(ExpenseCategory::class)->find($category->getId());
        self::assertSame('Groceries', $reloaded->getName());
        self::assertSame('#00ff00', $reloaded->getColor());
    }

    public function testCategoryNamesAreUniquePerUserButReusableAcrossUsers(): void
    {
        $user = $this->createUser('owner@example.com');
        $other = $this->createUser('other@example.com');

        $this->categories->create($user, 'Food', '#ff0000');

        // Same name for another user is fine.
        $this->categories->create($other, 'Food', '#00ff00');

        // Same name twice for the same user is rejected with a domain
        // exception (not a raw DBAL one), so callers can render a form
        // error / 409 instead of 500ing.
        $this->expectException(DuplicateCategoryNameException::class);
        $this->categories->create($user, 'Food', '#0000ff');
    }

    public function testDeleteUnusedCategorySucceeds(): void
    {
        $user = $this->createUser('owner@example.com');
        $category = $this->categories->create($user, 'Food', '#ff0000');

        $this->categories->delete($category);

        self::assertCount(0, $this->categories->getForOwner($user));
    }

    public function testDeleteUsedCategoryThrowsAndReassignsNothing(): void
    {
        $user = $this->createUser('owner@example.com');
        $default = $this->categories->ensureDefaultCategory($user);
        $subscription = $this->createSubscriptionWithCategory($user, $default);

        try {
            $this->categories->delete($default);
            self::fail('Deleting a category in use must throw.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('Reassign them first', $exception->getMessage());
        }

        // No silent reassignment: the subscription keeps its category,
        // and the category still exists.
        $this->em->clear();
        $reloaded = $this->em->getRepository(Subscription::class)->find($subscription->getId());
        self::assertNotNull($reloaded->getCategory());
        self::assertSame(ExpenseCategory::DEFAULT_NAME, $reloaded->getCategory()->getName());
        self::assertNotNull($this->em->getRepository(ExpenseCategory::class)->find($default->getId()));
    }

    public function testNewSubscriptionDefaultsToDefaultCategory(): void
    {
        $user = $this->createUser('owner@example.com');

        $subscription = $this->createSubscriptionWithCategory($user);

        self::assertNotNull($subscription->getCategory());
        self::assertSame(ExpenseCategory::DEFAULT_NAME, $subscription->getCategory()->getName());
        self::assertTrue($subscription->getCategory()->isOwnedBy($user));
    }

    public function testSubscriptionCanUseAnotherOwnedCategory(): void
    {
        $user = $this->createUser('owner@example.com');
        $food = $this->categories->create($user, 'Food', '#ff0000');

        $subscription = $this->createSubscriptionWithCategory($user, $food);

        self::assertSame('Food', $subscription->getCategory()->getName());
    }

    public function testCrossUserCategoryAssignmentIsRejectedOnAdd(): void
    {
        $owner = $this->createUser('owner@example.com');
        $other = $this->createUser('other@example.com');
        $foreign = $this->categories->create($other, 'Food', '#ff0000');

        $subscription = (new Subscription())
            ->setName('Netflix')
            ->setBillingCycle(BillingCycle::Monthly)
            ->setAmount(15.99)
            ->setNextPayment(new \DateTimeImmutable('2024-01-15'))
            ->setOwner($owner)
            ->setCategory($foreign);

        $this->expectException(\LogicException::class);
        $this->subscriptions->add($subscription);
    }

    public function testCrossUserCategoryAssignmentIsRejectedOnUpdate(): void
    {
        $owner = $this->createUser('owner@example.com');
        $other = $this->createUser('other@example.com');
        $foreign = $this->categories->create($other, 'Food', '#ff0000');

        $subscription = $this->createSubscriptionWithCategory($user = $owner);
        $subscription->setCategory($foreign);

        $this->expectException(\LogicException::class);
        $this->subscriptions->update($subscription);
    }
}
