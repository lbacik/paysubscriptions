<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Refresh-token families for single-use rotation (issue #91).
 *
 * oauth_refresh_family carries one row per User, client, scope set, and API
 * resource: initial authorization time, last use, the absolute deadline
 * (initial authorization plus 90 days), and revocation. Its token links map
 * each bundle refresh-token identifier to its family and record whether the
 * token was superseded by rotation; replaying a superseded token revokes the
 * whole family. Account deletion cascades.
 */
final class Version20260927120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add refresh-token families for single-use rotation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE oauth_refresh_family (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', user_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', client_id VARCHAR(191) NOT NULL, scopes JSON NOT NULL, audience VARCHAR(255) NOT NULL, issued_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_used_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', absolute_expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', revoked TINYINT(1) NOT NULL, INDEX IDX_REFRESH_FAMILY_USER (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE oauth_refresh_family_token (token_id VARCHAR(80) NOT NULL, family_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', superseded TINYINT(1) NOT NULL, INDEX IDX_REFRESH_FAMILY_TOKEN_FAMILY (family_id), PRIMARY KEY(token_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE oauth_refresh_family ADD CONSTRAINT FK_REFRESH_FAMILY_USER FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE oauth_refresh_family_token ADD CONSTRAINT FK_REFRESH_FAMILY_TOKEN_FAMILY FOREIGN KEY (family_id) REFERENCES oauth_refresh_family (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth_refresh_family_token DROP FOREIGN KEY FK_REFRESH_FAMILY_TOKEN_FAMILY');
        $this->addSql('ALTER TABLE oauth_refresh_family DROP FOREIGN KEY FK_REFRESH_FAMILY_USER');
        $this->addSql('DROP TABLE oauth_refresh_family_token');
        $this->addSql('DROP TABLE oauth_refresh_family');
    }
}
