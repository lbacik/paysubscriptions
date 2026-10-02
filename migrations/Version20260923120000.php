<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds User-owned expense categories and assigns every Subscription a
 * required category. Pre-category Users and Subscriptions are backfilled
 * with one default `Subscriptions` category per User; no data is lost on
 * `up()`. `down()` drops the `expense_category` table entirely, so any
 * category created or renamed after this migration ran is lost on rollback.
 */
final class Version20260923120000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Add expense_category table, require subscription.category_id, backfill defaults';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE expense_category (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', owner_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', name VARCHAR(100) NOT NULL, color VARCHAR(7) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE INDEX UNIQ_EXPENSE_CATEGORY_OWNER_NAME (owner_id, name), INDEX IDX_C02DDB387E3C61F9 (owner_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE expense_category ADD CONSTRAINT FK_C02DDB387E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE subscription ADD category_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');

        // One default `Subscriptions` category per User that does not have one yet.
        $this->addSql('INSERT INTO expense_category (id, owner_id, name, color, created_at, updated_at) SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), u.id, \'Subscriptions\', \'#577399\', NOW(), NOW() FROM `user` u LEFT JOIN expense_category ec ON ec.owner_id = u.id AND ec.name = \'Subscriptions\' WHERE ec.id IS NULL');

        // Assign every uncategorized Subscription to its owner's default category.
        $this->addSql('UPDATE subscription s INNER JOIN expense_category ec ON ec.owner_id = s.owner_id AND ec.name = \'Subscriptions\' SET s.category_id = ec.id WHERE s.category_id IS NULL');

        $this->addSql('ALTER TABLE subscription CHANGE category_id category_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D312469DE2 FOREIGN KEY (category_id) REFERENCES expense_category (id)');
        $this->addSql('CREATE INDEX IDX_A3C664D312469DE2 ON subscription (category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription DROP FOREIGN KEY FK_A3C664D312469DE2');
        $this->addSql('DROP INDEX IDX_A3C664D312469DE2 ON subscription');
        $this->addSql('ALTER TABLE subscription DROP category_id');
        $this->addSql('ALTER TABLE expense_category DROP FOREIGN KEY FK_C02DDB387E3C61F9');
        $this->addSql('DROP TABLE expense_category');
    }
}
