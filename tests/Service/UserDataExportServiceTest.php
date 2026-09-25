<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ExpenseCategory;
use App\Enum\BillingCycle;
use App\Service\ExpenseCategoryService;
use App\Service\UserDataExportService;
use App\Tests\DatabaseTestCase;

/**
 * Manual-request export (issue #48): the maintainer fulfills a User's emailed
 * request with exactly that User's application records, in machine-readable
 * form, and nothing else (no password hash, no other Users' data, no secrets).
 */
final class UserDataExportServiceTest extends DatabaseTestCase
{
    public function testExportContainsOnlyTheRequestingUsersRecords(): void
    {
        $user = $this->createUser('export@example.com');
        $other = $this->createUser('other@example.com');
        $this->createSubscription($user, 'Netflix');
        $this->createSubscription($other, 'Other Sub');

        $export = static::getContainer()->get(UserDataExportService::class)->export($user);

        self::assertSame('export@example.com', $export['account']['email']);
        $names = array_column($export['subscriptions'], 'name');
        self::assertContains('Netflix', $names);
        self::assertNotContains('Other Sub', $names);
        foreach ($export['subscriptions'] as $subscription) {
            self::assertSame('export@example.com', $subscription['ownerEmail']);
        }
    }

    public function testExportNeverIncludesCredentialsOrSecrets(): void
    {
        $user = $this->createUser('nosecrets@example.com');
        $this->createSubscription($user, 'Netflix');

        $export = static::getContainer()->get(UserDataExportService::class)->export($user);
        $encoded = json_encode($export);
        self::assertIsString($encoded);

        self::assertArrayNotHasKey('password', $export['account']);
        self::assertStringNotContainsStringIgnoringCase('password', $encoded);
        self::assertStringNotContainsStringIgnoringCase('hashedToken', $encoded);
        self::assertStringNotContainsStringIgnoringCase('selector', $encoded);
    }

    public function testExportIsMachineReadableJsonWithAccountAndSubscriptionData(): void
    {
        $user = $this->createUser('readable@example.com');
        $user->setTimezone('Europe/Warsaw');
        $user->setMainCurrency('USD');
        $this->em->flush();
        $subscription = $this->createSubscription($user, 'Netflix', BillingCycle::Yearly, 120.0, new \DateTime('2024-03-15'));
        $subscription->setNotes('Cancel via the provider website.');
        $this->em->flush();

        $export = static::getContainer()->get(UserDataExportService::class)->export($user);
        $decoded = json_decode(json_encode($export), true);
        self::assertIsArray($decoded);

        self::assertSame('readable@example.com', $decoded['account']['email']);
        self::assertSame('USD', $decoded['account']['mainCurrency']);
        self::assertSame('Europe/Warsaw', $decoded['account']['timezone']);
        self::assertArrayHasKey('expenseCategories', $decoded);
        self::assertArrayHasKey('limits', $decoded);

        self::assertCount(1, $decoded['subscriptions']);
        $row = $decoded['subscriptions'][0];
        self::assertSame('Netflix', $row['name']);
        self::assertSame('yearly', $row['billingCycle']);
        self::assertEquals(120.0, $row['amount']);
        self::assertSame('2024-03-15', $row['nextPayment']);
        self::assertSame('Cancel via the provider website.', $row['notes']);
        self::assertArrayHasKey('category', $row);
    }

    public function testExportIncludesOwnCategoriesAndLimits(): void
    {
        $user = $this->createUser('categories@example.com');
        $user->setSubscriptionsLimit(50);
        $this->em->flush();
        // Creating a Subscription also provisions the default category.
        $this->createSubscription($user, 'Netflix');

        /** @var ExpenseCategoryService $categories */
        $categories = static::getContainer()->get(ExpenseCategoryService::class);
        $custom = (new ExpenseCategory())->setName('Media')->setColor('#ff0000');
        $custom->setOwner($user);
        $this->em->persist($custom);
        $this->em->flush();

        $export = static::getContainer()->get(UserDataExportService::class)->export($user);
        $categoryNames = array_column($export['expenseCategories'], 'name');

        self::assertContains(ExpenseCategory::DEFAULT_NAME, $categoryNames);
        self::assertContains('Media', $categoryNames);
        self::assertSame(50, $export['limits']['subscriptions']);
    }
}
