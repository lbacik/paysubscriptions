<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\ExpenseCategory;
use App\Tests\DatabaseTestCase;
use App\Tests\WithoutDatabaseRollback;
use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Uid\Uuid;

/**
 * Replays the real migration chain against a scratch database:
 * "fresh" (empty DB, all migrations) and "migrated" (legacy rows written
 * under the pre-category schema, then the category migration).
 *
 * Needs a database. Runs real DDL, so it opts out of the per-test rollback
 * and re-migrates a clean database for the tests that follow.
 */
#[WithoutDatabaseRollback]
final class ExpenseCategoryMigrationTest extends DatabaseTestCase
{
    private const PREVIOUS_VERSION = 'DoctrineMigrations\Version20240928142037';

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->em->getConnection();
    }

    // Each test drops and rebuilds the database itself; Foundry's per-test reset would be wasted work.
    public static function _resetDatabaseBeforeEachTest(): void
    {
    }

    public static function tearDownAfterClass(): void
    {
        StaticDriver::setKeepStaticConnections(false);
        $kernel = static::bootKernel();
        foreach ([
            ['command' => 'doctrine:database:drop', '--force' => true, '--if-exists' => true],
            ['command' => 'doctrine:database:create'],
            ['command' => 'doctrine:migrations:migrate', '--no-interaction' => true, '--allow-no-migration' => true],
        ] as $input) {
            $application = new Application($kernel);
            $application->setAutoExit(false);
            $output = new BufferedOutput();
            if (0 !== $application->run(new ArrayInput($input), $output)) {
                throw new \RuntimeException(sprintf("Restoring the test database failed at '%s': %s", $input['command'], $output->fetch()));
            }
        }
        static::ensureKernelShutdown();
        StaticDriver::setKeepStaticConnections(true);

        parent::tearDownAfterClass();
    }

    private function runCommand(string $name, array $arguments = []): string
    {
        $application = new Application(static::$kernel);
        $application->setAutoExit(false);

        $output = new BufferedOutput();
        $exitCode = $application->run(new ArrayInput(array_merge(
            ['command' => $name],
            $arguments
        )), $output);

        self::assertSame(0, $exitCode, sprintf(
            "Command '%s' failed: %s",
            $name,
            $output->fetch()
        ));

        return $output->fetch();
    }

    private function resetDatabase(): void
    {
        $this->em->close();
        $this->runCommand('doctrine:database:drop', ['--force' => true, '--if-exists' => true]);
        $this->runCommand('doctrine:database:create');
        // Reopen the manager on the fresh database.
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->connection = $this->em->getConnection();
    }

    private function legacyUser(string $email): string
    {
        $id = Uuid::v7()->toBinary();
        $this->connection->insert('`user`', [
            'id' => $id,
            'email' => $email,
            'roles' => '[]',
            'password' => 'hashed',
            'is_verified' => 1,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
        ]);

        return $id;
    }

    private function legacySubscription(string $ownerId, string $name, ?float $monthly, ?float $yearly): void
    {
        $this->connection->insert('subscription', [
            'id' => Uuid::v7()->toBinary(),
            'owner_id' => $ownerId,
            'name' => $name,
            'first_payment' => '2024-01-15',
            'monthly' => $monthly,
            'yearly' => $yearly,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
        ]);
    }

    public function testFreshMigrationCreatesEmptyCategoryTableAndRequiredColumn(): void
    {
        $this->resetDatabase();
        $this->runCommand('doctrine:migrations:migrate', ['--no-interaction' => true, '--allow-no-migration' => true]);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM expense_category'));

        $nullable = $this->connection->fetchOne(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscription' AND COLUMN_NAME = 'category_id'"
        );
        self::assertSame('NO', $nullable, 'subscription.category_id must be NOT NULL after a fresh migration.');
    }

    public function testMigratedStateBackfillsLegacyUsersAndSubscriptions(): void
    {
        $this->resetDatabase();
        $this->runCommand('doctrine:migrations:migrate', [
            'version' => self::PREVIOUS_VERSION,
            '--no-interaction' => true,
        ]);

        // Legacy data, written exactly as the old code left it: no category anywhere.
        $ownerWithSubs = $this->legacyUser('legacy1@example.com');
        $otherWithSubs = $this->legacyUser('legacy2@example.com');
        $ownerWithoutSubs = $this->legacyUser('lonely@example.com');
        $this->legacySubscription($ownerWithSubs, 'Legacy Netflix', 15.99, null);
        $this->legacySubscription($ownerWithSubs, 'Legacy Prime', null, 139.00);
        $this->legacySubscription($otherWithSubs, 'Legacy Spotify', 9.99, null);

        $this->runCommand('doctrine:migrations:migrate', [
            '--no-interaction' => true,
            '--allow-no-migration' => true,
        ]);

        // One default category per user, even the one without subscriptions.
        $categories = $this->connection->fetchAllAssociative(
            'SELECT owner_id, name, color FROM expense_category ORDER BY name'
        );
        self::assertCount(3, $categories);
        foreach ($categories as $category) {
            self::assertSame(ExpenseCategory::DEFAULT_NAME, $category['name']);
            self::assertSame(ExpenseCategory::DEFAULT_COLOR, $category['color']);
        }

        // Every subscription kept its data and points at its owner's category.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.name, s.billing_cycle, s.amount, s.owner_id, s.category_id, ec.owner_id AS cat_owner, ec.name AS cat_name
             FROM subscription s JOIN expense_category ec ON ec.id = s.category_id
             ORDER BY s.name'
        );
        self::assertCount(3, $rows);

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['name']] = $row;
        }

        self::assertSame('monthly', $byName['Legacy Netflix']['billing_cycle']);
        self::assertSame('15.99', (string) $byName['Legacy Netflix']['amount']);
        self::assertSame('yearly', $byName['Legacy Prime']['billing_cycle']);
        self::assertSame('139.00', (string) $byName['Legacy Prime']['amount']);
        self::assertSame('monthly', $byName['Legacy Spotify']['billing_cycle']);
        self::assertSame('9.99', (string) $byName['Legacy Spotify']['amount']);

        foreach ($rows as $row) {
            self::assertSame($row['owner_id'], $row['category_id'] ? $row['cat_owner'] : null);
            self::assertSame(ExpenseCategory::DEFAULT_NAME, $row['cat_name']);
        }

        self::assertSame($ownerWithSubs, $byName['Legacy Netflix']['cat_owner']);
        self::assertSame($ownerWithSubs, $byName['Legacy Prime']['cat_owner']);
        self::assertSame($otherWithSubs, $byName['Legacy Spotify']['cat_owner']);

        // Nothing left uncategorized.
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM subscription WHERE category_id IS NULL'));

        // The lonely user got a category but no subscriptions were invented.
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM expense_category WHERE owner_id = ?',
            [$ownerWithoutSubs]
        ));
    }
}
