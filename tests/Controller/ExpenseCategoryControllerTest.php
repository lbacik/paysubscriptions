<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ExpenseCategory;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use App\Repository\ExpenseCategoryRepository;
use App\Tests\DatabaseTestCase;

/**
 * End-to-end coverage for category management and the subscription
 * category assignment, including cross-user rejection at the HTTP layer.
 *
 * Needs a database; skips cleanly where none is reachable.
 */
final class ExpenseCategoryControllerTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->recreateSchema();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isConnected()) {
            $this->dropSchema();
        }

        parent::tearDown();
    }

    private function createUser(string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPassword('hashed')
            ->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createCategory(User $owner, string $name = 'Food', string $color = '#ff0000'): ExpenseCategory
    {
        $category = (new ExpenseCategory())
            ->setName($name)
            ->setColor($color);
        $owner->addExpenseCategory($category);
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    public function testAnonymousCategoryPagesRedirectToLogin(): void
    {
        $this->client->request('GET', '/category');

        self::assertResponseRedirects('/login');
    }

    public function testUserCanCreateRenameAndDeleteOwnCategory(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->client->loginUser($user);

        // Create.
        $crawler = $this->client->request('GET', '/category/new');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form')->form([
            'expense_category[name]' => 'Streaming',
            'expense_category[color]' => '#123456',
        ]));
        self::assertResponseRedirects('/category');

        $repository = static::getContainer()->get(ExpenseCategoryRepository::class);
        $category = $repository->findOneBy(['owner' => $user, 'name' => 'Streaming']);
        self::assertNotNull($category);
        self::assertSame('#123456', $category->getColor());

        // The new category shows up on the index.
        $crawler = $this->client->request('GET', '/category');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Streaming', $crawler->text());

        // Rename + recolor.
        $crawler = $this->client->request('GET', '/category/' . $category->getId() . '/edit');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form')->form([
            'expense_category[name]' => 'Video',
            'expense_category[color]' => '#654321',
        ]));
        self::assertResponseRedirects('/category');

        $this->em->clear();
        $renamed = $repository->find($category->getId());
        self::assertSame('Video', $renamed->getName());
        self::assertSame('#654321', $renamed->getColor());

        // Delete (unused).
        $crawler = $this->client->request('GET', '/category/' . $category->getId());
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/category');
        // Assert at the database level: the functional client reboots the
        // kernel between requests, so any repository fetched earlier reads
        // from a stale entity manager.
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM expense_category WHERE id = ?',
            [$category->getId()->toBinary()]
        ));
    }

    public function testDeleteUsedCategoryIsRefusedWithoutReassignment(): void
    {
        $user = $this->createUser('owner@example.com');
        $category = $this->createCategory($user);

        $subscription = (new Subscription())
            ->setName('Netflix')
            ->setBillingCycle(BillingCycle::Monthly)
            ->setAmount(15.99)
            ->setNextPayment(new \DateTimeImmutable('2024-01-15'));
        // Maintain both sides: the first HTTP request reuses this test's
        // entity manager, so an owning-side-only link would leave the
        // inverse collections initialized-but-empty in memory.
        $user->addSubscription($subscription);
        $category->addSubscription($subscription);
        $this->em->persist($subscription);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/category/' . $category->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Reassign them before deleting', $crawler->text());

        $this->client->submit($crawler->filter('form')->form());
        self::assertResponseRedirects('/category');

        // Follow the redirect and check the flash: nothing was reassigned or deleted.
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Reassign them first', $crawler->text());

        $this->em->clear();
        $reloaded = $this->em->getRepository(Subscription::class)->find($subscription->getId());
        self::assertSame($category->getId()->toString(), $reloaded->getCategory()->getId()->toString());
    }

    public function testCrossUserCategoryAccessIsForbidden(): void
    {
        $owner = $this->createUser('owner@example.com');
        $intruder = $this->createUser('intruder@example.com');
        $category = $this->createCategory($owner);

        $this->client->loginUser($intruder);

        $this->client->request('GET', '/category/' . $category->getId() . '/edit');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/category/' . $category->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testNewSubscriptionFormCreatesDefaultCategoryForBrandNewUser(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/subscription/new');
        self::assertResponseIsSuccessful();

        $categoryField = $crawler->filter('select#subscription_category');
        self::assertCount(1, $categoryField);
        self::assertStringContainsString(ExpenseCategory::DEFAULT_NAME, $categoryField->text());

        // Submit without touching the category: the default is used.
        $this->client->submit($crawler->filter('form')->form([
            'subscription[name]' => 'Netflix',
            'subscription[billingCycle]' => BillingCycle::Monthly->value,
            'subscription[amount]' => '15.99',
            'subscription[nextPayment]' => '2024-01-15',
        ]));

        $subscription = $this->em->getRepository(Subscription::class)->findOneBy(['name' => 'Netflix']);
        self::assertNotNull($subscription);
        self::assertSame(ExpenseCategory::DEFAULT_NAME, $subscription->getCategory()->getName());
    }

    public function testNewSubscriptionFormReusesExistingCategoryInsteadOfCreatingDefault(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->createCategory($user, 'Food', '#ff0000');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/subscription/new');
        self::assertResponseIsSuccessful();

        $categoryField = $crawler->filter('select#subscription_category');
        self::assertCount(1, $categoryField);
        self::assertStringContainsString('Food', $categoryField->text());
        self::assertStringNotContainsString(ExpenseCategory::DEFAULT_NAME, $categoryField->text());

        $repository = static::getContainer()->get(ExpenseCategoryRepository::class);
        self::assertCount(1, $repository->findBy(['owner' => $user]));
    }

    public function testRenamedDefaultCategoryDoesNotReappearOnNewSubscriptionForm(): void
    {
        $user = $this->createUser('owner@example.com');
        $default = $this->createCategory($user, ExpenseCategory::DEFAULT_NAME, ExpenseCategory::DEFAULT_COLOR);
        $default->setName('Renamed');
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/subscription/new');
        self::assertResponseIsSuccessful();

        $repository = static::getContainer()->get(ExpenseCategoryRepository::class);
        self::assertCount(1, $repository->findBy(['owner' => $user]));
        self::assertNull($repository->findOneBy(['owner' => $user, 'name' => ExpenseCategory::DEFAULT_NAME]));
    }

    public function testDuplicateCategoryNameShowsFormErrorInsteadOf500(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->createCategory($user, 'Food', '#ff0000');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/category/new');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form')->form([
            'expense_category[name]' => 'Food',
            'expense_category[color]' => '#123456',
        ]));

        // Symfony returns 422 for a re-rendered form with validation errors,
        // not a 500 — that's the bug this test guards against.
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('already have a category with this name', $this->client->getResponse()->getContent());

        $repository = static::getContainer()->get(ExpenseCategoryRepository::class);
        self::assertCount(1, $repository->findBy(['owner' => $user, 'name' => 'Food']));
    }
}
