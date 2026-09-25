<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ExpenseCategory;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use App\Service\ExpenseCategoryService;
use App\Service\SubscriptionService;
use App\Tests\DatabaseTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

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

        // Same name twice for the same user is rejected.
        $this->expectException(UniqueConstraintViolationException::class);
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
