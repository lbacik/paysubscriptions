<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stores the account time zone and global email-reminder preferences on the User.
 */
final class Version20260923200000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Add timezone and email-reminder preferences to user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD timezone VARCHAR(64) DEFAULT \'UTC\' NOT NULL');
        $this->addSql('ALTER TABLE user ADD email_reminders_enabled TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE user ADD reminder_lead_days INT DEFAULT 3 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP timezone, DROP email_reminders_enabled, DROP reminder_lead_days');
    }
}
