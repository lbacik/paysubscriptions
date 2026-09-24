<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Durable send identity for forecast-renewal email reminders.
 *
 * One row per (subscription, renewal date): the unique pair lets retries and
 * concurrent workers claim the same scheduled renewal instead of sending it
 * twice. Rows carry no Subscription details (no name, amount, or email), only
 * the identifiers needed to correlate a delivery failure. Deleting a
 * Subscription cascades: orphaned identities disappear with it.
 */
final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add renewal_reminder send identity for forecast-renewal email reminders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE renewal_reminder (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', subscription_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', renewal_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', status VARCHAR(16) NOT NULL, sent_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE INDEX UNIQ_RENEWAL_REMINDER_SUBSCRIPTION_DATE (subscription_id, renewal_date), INDEX IDX_13F34E509A1887DC (subscription_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE renewal_reminder ADD CONSTRAINT FK_RENEWAL_REMINDER_SUBSCRIPTION FOREIGN KEY (subscription_id) REFERENCES subscription (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE renewal_reminder DROP FOREIGN KEY FK_RENEWAL_REMINDER_SUBSCRIPTION');
        $this->addSql('DROP TABLE renewal_reminder');
    }
}
