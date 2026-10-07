<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Revokes orphaned Client sessions and pending authorization codes left
 * without a Connection (issue #135).
 *
 * Before #182 and #195, two paths could leave a live refresh family without
 * a matching consent row for the same (User, Client identifier) pair: a
 * pending authorization code exchanged after the User disconnected, and the
 * RFC 7009 `/revoke` endpoint forgetting the consent while the pair's other
 * families stayed live. Connected apps lists consents only, so the User can
 * neither see nor revoke such orphans; they stay usable until the 30-day
 * idle or 90-day absolute limit.
 *
 * This migration marks every non-revoked refresh family that has no consent
 * row for the same (user_id, client_id) pair as revoked, supersedes its
 * token links, and revokes the bundle's refresh-token rows behind those
 * links — matching what App\Connection\ConnectionLifecycle::revokeSessions()
 * does at runtime. Pending authorization codes with no matching consent
 * (joined through the User's email, which is what the code row stores) are
 * revoked as well.
 *
 * Every statement only touches rows missing their consent and already-set
 * flags, so re-running is harmless. Families and codes that do have a
 * matching consent are never touched. The statements are UPDATEs only — no
 * table or column is created, dropped, or renamed — so the previous image
 * keeps reading the same shape (issue #100).
 */
final class Version20261007120000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Revoke refresh families and pending authorization codes left without a matching consent (orphaned Client sessions)';
    }

    public function up(Schema $schema): void
    {
        // Live families with no consent row for the same (User, Client) pair.
        $this->addSql(
            'UPDATE oauth_refresh_family f SET f.revoked = 1'
            .' WHERE f.revoked = 0 AND NOT EXISTS'
            .' (SELECT 1 FROM oauth_consent c WHERE c.user_id = f.user_id AND c.client_id = f.client_id)'
        );

        // Token links of consent-less families, including already-revoked
        // ones whose links a pre-module revocation may have left usable.
        $this->addSql(
            'UPDATE oauth_refresh_family_token l'
            .' INNER JOIN oauth_refresh_family f ON f.id = l.family_id SET l.superseded = 1'
            .' WHERE l.superseded = 0 AND NOT EXISTS'
            .' (SELECT 1 FROM oauth_consent c WHERE c.user_id = f.user_id AND c.client_id = f.client_id)'
        );

        // The bundle's own refresh-token rows behind those links.
        $this->addSql(
            'UPDATE oauth2_refresh_token rt'
            .' INNER JOIN oauth_refresh_family_token l ON l.token_id = rt.identifier'
            .' INNER JOIN oauth_refresh_family f ON f.id = l.family_id SET rt.revoked = 1'
            .' WHERE rt.revoked = 0 AND NOT EXISTS'
            .' (SELECT 1 FROM oauth_consent c WHERE c.user_id = f.user_id AND c.client_id = f.client_id)'
        );

        // Pending codes with no matching consent. The code row stores the
        // User's email rather than its id, so the match joins through `user`.
        // Codes with a NULL user identifier match nothing and are revoked:
        // fail-closed, they could never be exchanged under a Connection.
        $this->addSql(
            'UPDATE oauth2_authorization_code ac SET ac.revoked = 1'
            .' WHERE ac.revoked = 0 AND NOT EXISTS'
            .' (SELECT 1 FROM `user` u'
            .' INNER JOIN oauth_consent c ON c.user_id = u.id AND c.client_id = ac.client'
            .' WHERE u.email = ac.user_identifier)'
        );
    }

    public function down(Schema $schema): void
    {
        // No-op: revocation is not reversible. Re-granting consent cannot
        // resurrect the revoked families or codes; the Clients authorize
        // again and start fresh sessions under the standing Connection.
    }
}
