<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds User main currency and per-Subscription currency with a user-entered
 * converted amount. All columns are nullable so legacy amounts and Users
 * stay unchanged: existing Users confirm a main currency in their profile,
 * and legacy Subscriptions keep reporting raw amounts until edited.
 */
final class Version20260923000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add main_currency to user; currency, converted_amount and converted_currency to subscription (all nullable, legacy data unchanged).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD main_currency VARCHAR(3) DEFAULT NULL');
        $this->addSql('ALTER TABLE subscription ADD currency VARCHAR(3) DEFAULT NULL');
        $this->addSql('ALTER TABLE subscription ADD converted_amount NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE subscription ADD converted_currency VARCHAR(3) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription DROP converted_currency');
        $this->addSql('ALTER TABLE subscription DROP converted_amount');
        $this->addSql('ALTER TABLE subscription DROP currency');
        $this->addSql('ALTER TABLE user DROP main_currency');
    }
}
