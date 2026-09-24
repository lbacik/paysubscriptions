<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds optional free-text notes to subscriptions (#40). Nullable so existing
 * rows stay unchanged; plain text only, no attachments.
 */
final class Version20260924000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable notes to subscription (#40)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription ADD notes LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription DROP notes');
    }
}
