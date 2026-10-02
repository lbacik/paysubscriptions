<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sync the migrated schema to the entity mappings (issue #100).
 *
 * The v1 release gate validates the migrated schema against the mappings in
 * CI, and the validation surfaced three pre-existing drifts, all
 * backward-compatible for the previous image:
 *
 * - oauth_refresh_family.revoked and oauth_refresh_family_token.superseded
 *   gain the DEFAULT 0 the mappings declare. Columns stay NOT NULL; only
 *   future inserts without an explicit value change behavior.
 * - messenger_messages moves from the three single-column indexes the first
 *   migration created to the composite (queue_name, available_at,
 *   delivered_at, id) index the Doctrine transport mapping expects. Dropping
 *   secondary indexes never breaks the previous image's queries.
 *
 * No table or column is dropped or renamed.
 */
final class Version20260927120001 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Sync migrated schema to mappings for the v1 release gate';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth_refresh_family CHANGE revoked revoked TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE oauth_refresh_family_token CHANGE superseded superseded TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('DROP INDEX IDX_75EA56E0FB7336F0 ON messenger_messages');
        $this->addSql('DROP INDEX IDX_75EA56E0E3BD61CE ON messenger_messages');
        $this->addSql('DROP INDEX IDX_75EA56E016BA31DB ON messenger_messages');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX IDX_75EA56E0E3BD61CE ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)');
        $this->addSql('ALTER TABLE oauth_refresh_family_token CHANGE superseded superseded TINYINT(1) NOT NULL');
        $this->addSql('ALTER TABLE oauth_refresh_family CHANGE revoked revoked TINYINT(1) NOT NULL');
    }
}
