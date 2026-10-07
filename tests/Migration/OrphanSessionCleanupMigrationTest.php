<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\OAuthConsent;
use App\Entity\OAuthRefreshFamily;
use App\Entity\User;
use App\OAuth2\OAuth2Config;
use App\Tests\DatabaseTestCase;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261007120000;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Component\HttpFoundation\Response;

// Migration classes are not composer-autoloaded (they belong to the
// DoctrineMigrations namespace loaded by the migrations bundle), so the
// test loads the file under test explicitly.
require_once \dirname(__DIR__, 2).'/migrations/Version20261007120000.php';

/**
 * Replays the orphan-session cleanup migration's own SQL (issue #135).
 *
 * Seeds three Client sessions — one live session with a matching consent,
 * one live session whose consent was removed (the pre-#182/#195 orphan),
 * and one already-revoked session with its consent intact — plus a pending
 * authorization code for the kept and the orphaned pairs. After running the
 * migration's statements, only the orphan is newly revoked: its refresh
 * token fails with `invalid_grant`, its token link is superseded, and the
 * bundle's refresh-token row is revoked, matching what
 * App\Connection\ConnectionLifecycle does at runtime. Sessions and codes
 * with a matching consent keep working, and running the statements twice
 * is harmless.
 *
 * UPDATEs only, so the per-test rollback transaction survives: no DDL here.
 */
final class OrphanSessionCleanupMigrationTest extends DatabaseTestCase
{
    private const KEEP_CLIENT_ID = 'orphan-keep-cli';
    private const KEEP_CLIENT_NAME = 'Orphan Keep CLI';
    private const ORPHAN_CLIENT_ID = 'orphan-cli';
    private const ORPHAN_CLIENT_NAME = 'Orphan CLI';
    private const REGISTERED_REDIRECT = 'http://127.0.0.1/callback';
    private const REDIRECT_URI = 'http://127.0.0.1:54123/callback';
    private const EMAIL = 'orphan-cleanup-user@example.com';
    private const PASSWORD = 'Fixture-Password-1';

    private User $user;
    private string $verifier;
    private string $challenge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser(self::EMAIL, self::PASSWORD, true);
        $this->registerPublicClient(self::KEEP_CLIENT_ID, self::KEEP_CLIENT_NAME);
        $this->registerPublicClient(self::ORPHAN_CLIENT_ID, self::ORPHAN_CLIENT_NAME);

        $this->verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $this->challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    }

    public function testUpSqlRevokesOnlyConsentLessRows(): void
    {
        $statements = self::migrationStatements('up');

        self::assertCount(4, $statements);
        self::assertStringContainsString('UPDATE oauth_refresh_family f SET f.revoked = 1', $statements[0]);
        self::assertStringContainsString('oauth_consent', $statements[0]);
        self::assertStringContainsString('UPDATE oauth_refresh_family_token', $statements[1]);
        self::assertStringContainsString('UPDATE oauth2_refresh_token', $statements[2]);
        self::assertStringContainsString('UPDATE oauth2_authorization_code', $statements[3]);

        foreach ($statements as $statement) {
            self::assertStringContainsString('NOT EXISTS', $statement, 'every statement must spare rows with a matching consent');
        }
    }

    public function testDownIsNoop(): void
    {
        self::assertSame([], self::migrationStatements('down'), 'revocation is not reversible');
    }

    public function testMigrationRevokesOnlyTheOrphanAndIsIdempotent(): void
    {
        $this->client->loginUser($this->user);
        $revokedTokens = $this->authorizeAndExchange(self::KEEP_CLIENT_ID);
        $keptTokens = $this->authorizeAndExchange(self::KEEP_CLIENT_ID);
        $orphanTokens = $this->authorizeAndExchange(self::ORPHAN_CLIENT_ID);

        // One already-revoked session with its Connection intact: the RFC
        // 7009 endpoint revokes the single session and keeps the consent.
        $this->client->request('POST', '/revoke', [
            'token' => $revokedTokens['refresh_token'],
            'client_id' => self::KEEP_CLIENT_ID,
        ]);
        self::assertResponseIsSuccessful();

        // Pending codes, minted while both Connections still stand so the
        // orphan pair auto-approves instead of re-creating its consent.
        $keptCode = $this->authorizeOnly(self::KEEP_CLIENT_ID);
        $orphanCode = $this->authorizeOnly(self::ORPHAN_CLIENT_ID);

        // The pre-#182/#195 orphan: the consent is gone while the family
        // and the pending code stay live.
        $this->removeConsent(self::ORPHAN_CLIENT_ID);

        $revokedId = $this->familyId(self::KEEP_CLIENT_ID, true);
        $keptId = $this->familyId(self::KEEP_CLIENT_ID, false);
        $orphanId = $this->familyId(self::ORPHAN_CLIENT_ID, false);

        // Preconditions: the orphan is fully live, the kept session is
        // untouched, and the revoked session already rests.
        self::assertFalse($this->familyRevoked($orphanId));
        self::assertSame([0, 0], $this->linkAndBundleFlags($orphanId));
        self::assertFalse($this->familyRevoked($keptId));
        self::assertTrue($this->familyRevoked($revokedId));
        self::assertSame(2, $this->pendingCodeCount());

        $statements = self::migrationStatements('up');
        $this->runStatements($statements);

        // Only the orphan is newly revoked, with its link superseded and
        // its bundle row revoked, matching the module's revoke.
        self::assertTrue($this->familyRevoked($orphanId));
        self::assertSame([1, 1], $this->linkAndBundleFlags($orphanId));
        self::assertFalse($this->familyRevoked($keptId));
        self::assertSame([0, 0], $this->linkAndBundleFlags($keptId));
        self::assertTrue($this->familyRevoked($revokedId));

        // The kept Connection stands; the orphaned one stays gone.
        self::assertNotNull($this->findConsent(self::KEEP_CLIENT_ID));
        self::assertNull($this->findConsent(self::ORPHAN_CLIENT_ID));

        // The orphan's refresh token fails with `invalid_grant`…
        $this->refresh($orphanTokens['refresh_token'], self::ORPHAN_CLIENT_ID);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());

        // …while the kept session keeps refreshing…
        $rotated = $this->refresh($keptTokens['refresh_token'], self::KEEP_CLIENT_ID);
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('access_token', $rotated);

        // …the orphaned pending code no longer exchanges…
        $this->exchangeCode($orphanCode, self::ORPHAN_CLIENT_ID);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());

        // …and the kept pending code still does.
        $this->exchangeCode($keptCode, self::KEEP_CLIENT_ID);
        self::assertResponseIsSuccessful();

        // Running the migration twice is harmless: flags are unchanged and
        // the kept session (now on its rotated token) still works while the
        // orphan still fails.
        $this->runStatements($statements);

        self::assertTrue($this->familyRevoked($orphanId));
        self::assertSame([1, 1], $this->linkAndBundleFlags($orphanId));
        self::assertFalse($this->familyRevoked($keptId));
        self::assertNotNull($this->findConsent(self::KEEP_CLIENT_ID));

        $this->refresh($orphanTokens['refresh_token'], self::ORPHAN_CLIENT_ID);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());

        $this->refresh($rotated['refresh_token'], self::KEEP_CLIENT_ID);
        self::assertResponseIsSuccessful();
    }

    /**
     * @return list<string>
     */
    private static function migrationStatements(string $direction): array
    {
        $reflection = new \ReflectionClass(Version20261007120000::class);
        /** @var Version20261007120000 $migration */
        $migration = $reflection->newInstanceWithoutConstructor();
        $migration->{$direction}(new Schema());

        return array_map(
            static fn ($query) => $query->getStatement(),
            $migration->getSql(),
        );
    }

    /**
     * @param list<string> $statements
     */
    private function runStatements(array $statements): void
    {
        $connection = $this->freshEm()->getConnection();
        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
        $this->freshEm()->clear();
    }

    /**
     * Binary family id for one session of a pair: the revoked (or live) one.
     */
    private function familyId(string $clientId, bool $revoked): string
    {
        $family = $this->freshEm()->getRepository(OAuthRefreshFamily::class)->findOneBy([
            'clientId' => $clientId,
            'revoked' => $revoked,
        ]);
        self::assertNotNull($family, sprintf('expected a %s family for client %s', $revoked ? 'revoked' : 'live', $clientId));
        self::assertSame(self::EMAIL, $family->getUser()?->getEmail());

        $id = $family->getId();
        self::assertNotNull($id);

        return $id->toBinary();
    }

    private function familyRevoked(string $familyIdBinary): bool
    {
        return 1 === (int) $this->freshEm()->getConnection()->fetchOne(
            'SELECT revoked FROM oauth_refresh_family WHERE id = ?',
            [$familyIdBinary],
        );
    }

    /**
     * @return array{0: int, 1: int} [link superseded, bundle token revoked]
     */
    private function linkAndBundleFlags(string $familyIdBinary): array
    {
        /** @var array{superseded: string|int, bundle_revoked: string|int}|false $row */
        $row = $this->freshEm()->getConnection()->fetchAssociative(
            'SELECT l.superseded, rt.revoked AS bundle_revoked'
            .' FROM oauth_refresh_family_token l'
            .' JOIN oauth2_refresh_token rt ON rt.identifier = l.token_id'
            .' WHERE l.family_id = ?',
            [$familyIdBinary],
        );
        self::assertNotFalse($row, 'expected exactly one token link with its bundle row');

        return [(int) $row['superseded'], (int) $row['bundle_revoked']];
    }

    private function pendingCodeCount(): int
    {
        return (int) $this->freshEm()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM oauth2_authorization_code WHERE revoked = 0',
        );
    }

    private function findConsent(string $clientId): ?OAuthConsent
    {
        return $this->freshEm()->getRepository(OAuthConsent::class)->findOneBy(['clientId' => $clientId]);
    }

    private function removeConsent(string $clientId): void
    {
        $em = $this->freshEm();
        $consent = $em->getRepository(OAuthConsent::class)->findOneBy(['clientId' => $clientId]);
        self::assertNotNull($consent, 'precondition: the doomed consent exists before removal');

        $em->remove($consent);
        $em->flush();
        $em->clear();
    }

    /**
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    private function authorizeAndExchange(string $clientId): array
    {
        $code = $this->authorizeOnly($clientId);

        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => $this->verifier,
        ]);
        self::assertResponseIsSuccessful();

        /** @var array{token_type: string, expires_in: int, access_token: string, refresh_token: string} */
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function authorizeOnly(string $clientId): string
    {
        $url = $this->authorizeUrl($clientId);
        $crawler = $this->client->request('GET', $url);

        if (Response::HTTP_FOUND === $this->client->getResponse()->getStatusCode()) {
            return $this->codeFromRedirect();
        }

        $csrf = $crawler->filter('#oauth-consent-form input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($csrf);
        $this->client->request('POST', $url, ['decision' => 'allow', '_csrf_token' => $csrf]);

        return $this->codeFromRedirect();
    }

    /**
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    private function refresh(string $refreshToken, string $clientId): array
    {
        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
        ]);

        /** @var array{token_type: string, expires_in: int, access_token: string, refresh_token: string} */
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function exchangeCode(string $code, string $clientId): void
    {
        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => $this->verifier,
        ]);
    }

    private function authorizeUrl(string $clientId): string
    {
        return '/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => OAuth2Config::SCOPE_FULL,
            'state' => 'orphan-cleanup-state',
            'code_challenge' => $this->challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    private function codeFromRedirect(): string
    {
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertArrayHasKey('code', $query);
        self::assertIsString($query['code']);

        return $query['code'];
    }

    private function registerPublicClient(string $identifier, string $name): void
    {
        $manager = static::getContainer()->get(ClientManagerInterface::class);

        $client = new Client($name, $identifier, null);
        $client->setRedirectUris(new RedirectUri(self::REGISTERED_REDIRECT));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(new Scope(OAuth2Config::SCOPE_FULL));
        $manager->save($client);
    }
}
