<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replaces the implicit monthly-versus-yearly amount shape with an explicit
 * billing cycle, amount and next known payment date (#37).
 *
 * Backfill rule (legacy rows keep their meaning):
 * - billing_cycle is 'monthly' when the legacy monthly amount is set,
 *   otherwise 'yearly'. A legacy row with both amounts set keeps the monthly
 *   one and silently discards the yearly value; a row with neither becomes a
 *   zero-amount yearly plan. Both cases violate the old exactly-one-amount
 *   invariant, so no meaning is lost that the old validation would have
 *   accepted. Both are logged below rather than fixed here: the new
 *   `#[Assert\Positive]` on amount means a zero-amount row surfaces to its
 *   owner as a validation error the next time they touch the form, which is
 *   the correct outcome for data the app never should have allowed - but it
 *   must be visible to whoever runs this migration, not just to that owner
 *   months later.
 * - amount is the legacy monthly or yearly amount (COALESCE, monthly wins).
 * - next_payment copies first_payment. The copied date may lie in the past;
 *   renewal calculation rolls forward from the anchor, and the user edits the
 *   date afterwards. Names, amounts, owners and identities are untouched.
 *
 * Not transactional (see isTransactional()): every ALTER TABLE below is DDL,
 * and MySQL implicitly commits the open transaction before and after each
 * one regardless of what Doctrine or --all-or-nothing asks for. Wrapping
 * this migration in a BEGIN/COMMIT would silently protect nothing while
 * claiming to - the backfill UPDATE is the only statement a transaction
 * could actually roll back, and by the time it runs the ADD COLUMN
 * statements before it are already committed. What actually keeps a
 * mid-migration failure recoverable is every step below being safe to
 * re-run: each ADD/DROP COLUMN is guarded by the schema it's given, so
 * re-running after a failure skips whatever already landed instead of
 * failing on a duplicate column.
 */
final class Version20260923190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Explicit billing cycle, amount and next payment date for subscriptions (#37)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('subscription');

        // Only a real connection can answer these counts; a migration built to
        // merely inspect its planned SQL (as the replay test does, via
        // ReflectionClass::newInstanceWithoutConstructor()) has none, and the
        // diagnostic is skippable there since no rows are actually touched.
        if (null !== $this->connection && $table->hasColumn('monthly') && $table->hasColumn('yearly')) {
            $bothSet = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM subscription WHERE monthly IS NOT NULL AND yearly IS NOT NULL'
            );
            $neitherSet = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM subscription WHERE monthly IS NULL AND yearly IS NULL'
            );

            if ($bothSet > 0) {
                $this->write(sprintf(
                    '%d row(s) have both monthly and yearly set; keeping monthly and discarding the yearly value.',
                    $bothSet
                ));
            }

            if ($neitherSet > 0) {
                $this->write(sprintf(
                    '%d row(s) have neither monthly nor yearly set; backfilling amount = 0.00, which fails the new '
                    .'Assert\Positive constraint and will surface as a validation error the next time the owner '
                    .'edits the subscription.',
                    $neitherSet
                ));
            }
        }

        if (! $table->hasColumn('billing_cycle')) {
            $this->addSql('ALTER TABLE subscription ADD billing_cycle VARCHAR(16) NOT NULL DEFAULT \'monthly\'');
        }
        if (! $table->hasColumn('amount')) {
            $this->addSql('ALTER TABLE subscription ADD amount NUMERIC(10, 2) NOT NULL DEFAULT 0');
        }
        if (! $table->hasColumn('next_payment')) {
            $this->addSql('ALTER TABLE subscription ADD next_payment DATE NOT NULL DEFAULT \'1970-01-01\'');
        }

        if ($table->hasColumn('monthly') || $table->hasColumn('yearly')) {
            $this->addSql('UPDATE subscription SET billing_cycle = CASE WHEN monthly IS NOT NULL THEN \'monthly\' ELSE \'yearly\' END, amount = COALESCE(monthly, yearly, 0), next_payment = first_payment');
        }

        $this->addSql('ALTER TABLE subscription ALTER COLUMN billing_cycle DROP DEFAULT');
        $this->addSql('ALTER TABLE subscription ALTER COLUMN amount DROP DEFAULT');
        $this->addSql('ALTER TABLE subscription ALTER COLUMN next_payment DROP DEFAULT');

        if ($table->hasColumn('monthly')) {
            $this->addSql('ALTER TABLE subscription DROP COLUMN monthly');
        }
        if ($table->hasColumn('yearly')) {
            $this->addSql('ALTER TABLE subscription DROP COLUMN yearly');
        }
        if ($table->hasColumn('first_payment')) {
            $this->addSql('ALTER TABLE subscription DROP COLUMN first_payment');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('subscription');

        if (! $table->hasColumn('monthly')) {
            $this->addSql('ALTER TABLE subscription ADD monthly NUMERIC(10, 2) DEFAULT NULL');
        }
        if (! $table->hasColumn('yearly')) {
            $this->addSql('ALTER TABLE subscription ADD yearly NUMERIC(10, 2) DEFAULT NULL');
        }
        if (! $table->hasColumn('first_payment')) {
            $this->addSql('ALTER TABLE subscription ADD first_payment DATE NOT NULL DEFAULT \'1970-01-01\'');
        }

        if ($table->hasColumn('billing_cycle') || $table->hasColumn('amount')) {
            $this->addSql('UPDATE subscription SET monthly = CASE WHEN billing_cycle = \'monthly\' THEN amount ELSE NULL END, yearly = CASE WHEN billing_cycle = \'yearly\' THEN amount ELSE NULL END, first_payment = next_payment');
        }

        $this->addSql('ALTER TABLE subscription ALTER COLUMN first_payment DROP DEFAULT');

        if ($table->hasColumn('billing_cycle')) {
            $this->addSql('ALTER TABLE subscription DROP COLUMN billing_cycle');
        }
        if ($table->hasColumn('amount')) {
            $this->addSql('ALTER TABLE subscription DROP COLUMN amount');
        }
        if ($table->hasColumn('next_payment')) {
            $this->addSql('ALTER TABLE subscription DROP COLUMN next_payment');
        }
    }
}
