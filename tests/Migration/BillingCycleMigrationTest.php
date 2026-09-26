<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260923190000;
use PDO;
use PHPUnit\Framework\TestCase;

// Migration classes are not composer-autoloaded (they belong to the
// DoctrineMigrations namespace loaded by the migrations bundle), so the
// replay test loads the file under test explicitly.
require_once \dirname(__DIR__, 2).'/migrations/Version20260923190000.php';

/**
 * Guards the billing-cycle migration (#37) at its public seam: the SQL the
 * production migration command replays.
 *
 * testUpSqlCarriesBackfillRule runs anywhere; testBackfillOnRepresentativeLegacyRows
 * replays those exact statements against a real MySQL legacy schema and is
 * skipped when no database is reachable (CI's DB-less checks job).
 */
final class BillingCycleMigrationTest extends TestCase
{
    public function testUpSqlCarriesBackfillRule(): void
    {
        $statements = self::migrationStatements('up');

        self::assertContains('ALTER TABLE subscription ADD billing_cycle VARCHAR(16) NOT NULL DEFAULT \'monthly\'', $statements);
        self::assertContains('ALTER TABLE subscription ADD amount NUMERIC(10, 2) NOT NULL DEFAULT 0', $statements);
        self::assertContains('ALTER TABLE subscription ADD next_payment DATE NOT NULL DEFAULT \'1970-01-01\'', $statements);
        self::assertContains(
            'UPDATE subscription SET billing_cycle = CASE WHEN monthly IS NOT NULL THEN \'monthly\' ELSE \'yearly\' END, amount = COALESCE(monthly, yearly, 0), next_payment = first_payment',
            $statements,
        );
        self::assertContains('ALTER TABLE subscription DROP COLUMN monthly', $statements);
        self::assertContains('ALTER TABLE subscription DROP COLUMN yearly', $statements);
        self::assertContains('ALTER TABLE subscription DROP COLUMN first_payment', $statements);
    }

    public function testBackfillOnRepresentativeLegacyRows(): void
    {
        $pdo = self::mysql();
        if ($pdo === null) {
            self::markTestSkipped('No MySQL reachable for the migration replay test.');
        }

        $database = 'billing_migration_test_'.bin2hex(random_bytes(4));
        $pdo->exec("CREATE DATABASE `{$database}`");
        $pdo->exec("USE `{$database}`");
        $pdo->exec(
            'CREATE TABLE subscription ('
            .'id BINARY(16) NOT NULL PRIMARY KEY, '
            .'owner_id BINARY(16) NOT NULL, '
            .'name VARCHAR(255) NOT NULL, '
            .'first_payment DATE NOT NULL, '
            .'monthly NUMERIC(10, 2) DEFAULT NULL, '
            .'yearly NUMERIC(10, 2) DEFAULT NULL, '
            .'created_at DATETIME NOT NULL, '
            .'updated_at DATETIME NOT NULL)'
        );

        $insert = $pdo->prepare(
            'INSERT INTO subscription (id, owner_id, name, first_payment, monthly, yearly, created_at, updated_at)'
            .' VALUES (UUID_TO_BIN(UUID()), UUID_TO_BIN(UUID()), ?, ?, ?, ?, NOW(), NOW())'
        );
        $rows = [
            // name, first_payment, monthly, yearly
            ['Netflix monthly', '2024-01-15', 15.99, null],
            ['Gym month-end', '2023-01-31', 29.99, null],
            ['Prime yearly', '2023-05-20', null, 139.00],
            ['Leap yearly', '2024-02-29', null, 99.00],
            ['Both set prefers monthly', '2024-03-10', 10.00, 100.00],
            ['Neither set becomes zero yearly', '2024-03-10', null, null],
        ];
        foreach ($rows as $row) {
            $insert->execute($row);
        }

        foreach (self::migrationStatements('up') as $statement) {
            $pdo->exec($statement);
        }

        $migrated = $pdo->query(
            'SELECT BIN_TO_UUID(id) AS id, BIN_TO_UUID(owner_id) AS owner_id, name, billing_cycle, amount, DATE_FORMAT(next_payment, \'%Y-%m-%d\') AS next_payment'
            .' FROM subscription ORDER BY name'
        )->fetchAll(PDO::FETCH_ASSOC);

        $byName = [];
        foreach ($migrated as $row) {
            $byName[$row['name']] = $row;
        }

        self::assertSame(['monthly', '15.99', '2024-01-15'], [$byName['Netflix monthly']['billing_cycle'], $byName['Netflix monthly']['amount'], $byName['Netflix monthly']['next_payment']]);
        self::assertSame(['monthly', '29.99', '2023-01-31'], [$byName['Gym month-end']['billing_cycle'], $byName['Gym month-end']['amount'], $byName['Gym month-end']['next_payment']]);
        self::assertSame(['yearly', '139.00', '2023-05-20'], [$byName['Prime yearly']['billing_cycle'], $byName['Prime yearly']['amount'], $byName['Prime yearly']['next_payment']]);
        self::assertSame(['yearly', '99.00', '2024-02-29'], [$byName['Leap yearly']['billing_cycle'], $byName['Leap yearly']['amount'], $byName['Leap yearly']['next_payment']]);
        self::assertSame(['monthly', '10.00', '2024-03-10'], [$byName['Both set prefers monthly']['billing_cycle'], $byName['Both set prefers monthly']['amount'], $byName['Both set prefers monthly']['next_payment']]);
        self::assertSame(['yearly', '0.00', '2024-03-10'], [$byName['Neither set becomes zero yearly']['billing_cycle'], $byName['Neither set becomes zero yearly']['amount'], $byName['Neither set becomes zero yearly']['next_payment']]);

        // Identities, names and owners survive the migration untouched.
        self::assertCount(6, $migrated);
        foreach ($migrated as $row) {
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $row['id']);
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $row['owner_id']);
        }

        // Legacy columns are gone; new columns reject NULLs.
        $columns = $pdo->query('SHOW COLUMNS FROM subscription')->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('billing_cycle', $columns);
        self::assertContains('amount', $columns);
        self::assertContains('next_payment', $columns);
        self::assertNotContains('monthly', $columns);
        self::assertNotContains('yearly', $columns);
        self::assertNotContains('first_payment', $columns);

        $nullability = $pdo->query(
            "SELECT COLUMN_NAME, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$database}' AND TABLE_NAME = 'subscription'"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame('NO', $nullability['billing_cycle']);
        self::assertSame('NO', $nullability['amount']);
        self::assertSame('NO', $nullability['next_payment']);

        $pdo->exec("DROP DATABASE `{$database}`");
    }

    /**
     * @return list<string>
     */
    private static function migrationStatements(string $direction): array
    {
        $reflection = new \ReflectionClass(Version20260923190000::class);
        /** @var Version20260923190000 $migration */
        $migration = $reflection->newInstanceWithoutConstructor();
        $migration->{$direction}('up' === $direction ? self::legacySchema() : self::migratedSchema());

        return array_map(
            static fn($query) => $query->getStatement(),
            $migration->getSql(),
        );
    }

    /**
     * Mirrors the pre-migration table shape, so the guard clauses in up()
     * (which check the schema they're handed, not just $this->addSql) see the
     * same "nothing landed yet" state a real first run would.
     */
    private static function legacySchema(): Schema
    {
        $schema = new Schema();
        $table = $schema->createTable('subscription');
        $table->addColumn('monthly', 'decimal', ['notnull' => false]);
        $table->addColumn('yearly', 'decimal', ['notnull' => false]);
        $table->addColumn('first_payment', 'date', ['notnull' => true]);

        return $schema;
    }

    /**
     * Mirrors the post-migration table shape, for the down() guard clauses.
     */
    private static function migratedSchema(): Schema
    {
        $schema = new Schema();
        $table = $schema->createTable('subscription');
        $table->addColumn('billing_cycle', 'string', ['length' => 16]);
        $table->addColumn('amount', 'decimal', ['notnull' => true]);
        $table->addColumn('next_payment', 'date', ['notnull' => true]);

        return $schema;
    }

    private static function mysql(): ?PDO
    {
        $url = getenv('DATABASE_URL') ?: 'mysql://root:root@127.0.0.1:3306/paysub';
        if (!str_starts_with($url, 'mysql://')) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d', $parts['host'] ?? '127.0.0.1', $parts['port'] ?? 3306),
                $parts['user'] ?? 'root',
                $parts['pass'] ?? '',
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $pdo;
        } catch (\PDOException) {
            return null;
        }
    }
}
