<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\OAuth2\ApiAccessTokenEntity;
use App\OAuth2\OAuth2Config;
use App\Tests\DatabaseTestCase;
use DateTimeImmutable;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\Bundle\OAuth2ServerBundle\Entity\Client as ClientEntity;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signing-key rotation and encryption-material rotation for OAuth2 (issue #94).
 *
 * - Every issued access token carries the signing-key identifier (`kid`).
 * - Routine rotation: while the previous public key stays published, tokens
 *   signed with either key (identified by `kid`, or untried legacy tokens
 *   without one) open the API; unknown key identifiers fail closed.
 * - Emergency rotation: dropping the previous key immediately invalidates
 *   everything it signed, and revoking all refresh-token families forces
 *   clients to authorize again, while the current key keeps working.
 * - Encryption rotation: outstanding refresh tokens and authorization codes
 *   fail closed with protocol errors (never 500s), and a fresh authorization
 *   under the new key recovers.
 */
final class OAuthKeyRotationTest extends DatabaseTestCase
{
    private const CLIENT_ID = 'paysubs-cli';
    private const REGISTERED_REDIRECT = 'http://127.0.0.1/callback';
    private const REDIRECT_URI = 'http://127.0.0.1:54123/callback';
    private const ISSUER = 'http://localhost';
    private const CURRENT_KID = 'test-key-1';
    private const PREVIOUS_KID = 'test-key-0';

    private User $user;
    private string $verifier;
    private string $challenge;

    /** @var array<string, string|null> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('rotation-user@example.com', 'Fixture-Password-1', true);
        $this->registerPublicClient();

        $this->verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $this->challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            if (null === $value) {
                unset($_SERVER[$name], $_ENV[$name]);
            } else {
                $_SERVER[$name] = $_ENV[$name] = $value;
            }
        }
        $this->envBackup = [];
        static::ensureKernelShutdown();

        parent::tearDown();
    }

    public function testIssuedAccessTokensCarryKeyIdentifier(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        self::assertSame(self::CURRENT_KID, $this->tokenKid($first['access_token']));

        $second = $this->refresh($first['refresh_token']);
        self::assertResponseIsSuccessful();
        self::assertSame(self::CURRENT_KID, $this->tokenKid($second['access_token']));
    }

    public function testRoutineRotationAcceptsBothKeys(): void
    {
        $this->publishPreviousKey();

        // Token signed with the new key and its `kid`: accepted.
        $this->client->request('GET', '/api', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken(self::CURRENT_KID, 'private.pem'),
        ]);
        self::assertResponseIsSuccessful();

        // Token signed with the previous key and its `kid`: accepted while
        // the previous verification key stays published.
        $this->client->request('GET', '/api', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken(self::PREVIOUS_KID, 'previous-private.pem'),
        ]);
        self::assertResponseIsSuccessful();

        // Legacy token without a `kid`, signed with the previous key: still
        // accepted during the overlap (tried against the current key, then
        // the previous one).
        $this->client->request('GET', '/api', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken(self::PREVIOUS_KID, 'previous-private.pem', false),
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testUnknownKeyIdentifierIsRejected(): void
    {
        $this->publishPreviousKey();

        // Valid signature under the current key, but a `kid` nobody
        // configured: fail closed instead of falling back to trial verification.
        $this->client->request('GET', '/api', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('unknown-key', 'private.pem'),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testPreviousKeyIdWithoutPublishedKeyIsRejected(): void
    {
        // No rotation in flight: a token pointing at the previous `kid` must
        // not verify, even though the deployed key once carried that label.
        $this->client->request('GET', '/api', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken(self::PREVIOUS_KID, 'previous-private.pem'),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testEmergencyRotationInvalidatesOldKeyAndRevokesSessions(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();
        $oldAccessToken = $this->craftToken(self::PREVIOUS_KID, 'previous-private.pem');

        $this->publishPreviousKey();

        // Steady state with the rotation in flight: both keys open the API
        // and the session refreshes.
        $this->client->request('GET', '/api', [], [], ['HTTP_Authorization' => 'Bearer '.$oldAccessToken]);
        self::assertResponseIsSuccessful();

        // Emergency: the compromised key is unpublished and every
        // Client session is revoked.
        $this->unpublishPreviousKey();
        $revoked = $this->runRevokeCommand();
        self::assertStringContainsString('Revoked 1 Client session', $revoked);

        // The old key stops validating immediately…
        $this->client->request('GET', '/api', [], [], ['HTTP_Authorization' => 'Bearer '.$oldAccessToken]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // …the session cannot be refreshed anymore: authorize again…
        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $first['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());

        // …while the current key keeps working: a fresh authorization mints a
        // usable session without any operator cleanup beyond the revocation.
        $this->client->loginUser($this->user);
        $recovery = $this->authorizeAndExchange();
        self::assertArrayHasKey('access_token', $recovery);
        $this->client->request('GET', '/api', [], [], ['HTTP_Authorization' => 'Bearer '.$recovery['access_token']]);
        self::assertResponseIsSuccessful();
        $rotated = $this->refresh($recovery['refresh_token']);
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('access_token', $rotated);
    }

    public function testEncryptionRotationFailsClosedAndRecoversThroughReauthorization(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();
        $outstandingCode = $this->authorizeCode();

        // Controlled rotation of the refresh-token encryption material.
        $this->setEnvAndReboot('OAUTH_ENCRYPTION_KEY', 'rotated-dummy-encryption-key-for-tests-only-02');

        // Outstanding refresh tokens fail with a protocol error, never a 500:
        // the Client session behind them is unreachable, so clients must authorize again.
        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $first['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());

        // Outstanding authorization codes fail the same way.
        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $outstandingCode,
            'code_verifier' => $this->verifier,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // Fresh authorization under the new material recovers fully.
        $this->client->loginUser($this->user);
        $recovery = $this->authorizeAndExchange();
        self::assertArrayHasKey('refresh_token', $recovery);
        $rotated = $this->refresh($recovery['refresh_token']);
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('access_token', $rotated);
    }

    private function publishPreviousKey(): void
    {
        $this->setEnvAndReboot('OAUTH_PREVIOUS_PUBLIC_KEY', $this->fixturePath('previous-public.pem'));
        $this->setEnvAndReboot('OAUTH_PREVIOUS_KEY_ID', self::PREVIOUS_KID);
    }

    private function unpublishPreviousKey(): void
    {
        $this->setEnvAndReboot('OAUTH_PREVIOUS_PUBLIC_KEY', '');
        $this->setEnvAndReboot('OAUTH_PREVIOUS_KEY_ID', '');
    }

    private function setEnvAndReboot(string $name, string $value): void
    {
        if (!\array_key_exists($name, $this->envBackup)) {
            $backup = $_SERVER[$name] ?? $_ENV[$name] ?? null;
            $this->envBackup[$name] = \is_string($backup) ? $backup : null;
        }

        $_SERVER[$name] = $_ENV[$name] = $value;
        static::ensureKernelShutdown();
    }

    private function runRevokeCommand(): string
    {
        $application = new Application($this->client->getKernel());
        $command = $application->find('app:oauth:revoke-refresh-families');
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());

        return $tester->getDisplay();
    }

    private function craftToken(string $keyId, string $privateKeyFile, bool $withKid = true): string
    {
        $entity = new ApiAccessTokenEntity(self::ISSUER, OAuth2Config::API_AUDIENCE, $withKid ? $keyId : null);
        $entity->setIdentifier(bin2hex(random_bytes(16)));
        $clientEntity = new ClientEntity();
        $clientEntity->setIdentifier(self::CLIENT_ID);
        $clientEntity->setName('PaySubscriptions CLI');
        $entity->setClient($clientEntity);
        $entity->setUserIdentifier('rotation-user@example.com');
        $entity->addScope(new RotationTestScope(OAuth2Config::SCOPE_FULL));
        $entity->setExpiryDateTime(new DateTimeImmutable('+15 minutes'));
        $entity->setPrivateKey(new CryptKey($this->fixturePath($privateKeyFile)));

        return $entity->toString();
    }

    private function tokenKid(string $jwt): ?string
    {
        $token = (new Parser(new JoseEncoder()))->parse($jwt);
        self::assertInstanceOf(Plain::class, $token);

        $kid = $token->headers()->get('kid');

        return \is_string($kid) ? $kid : null;
    }

    private function fixturePath(string $file): string
    {
        return static::getContainer()->getParameter('kernel.project_dir').'/tests/Fixtures/oauth/'.$file;
    }

    /**
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    private function authorizeAndExchange(): array
    {
        $code = $this->authorizeCode();

        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => $this->verifier,
        ]);
        self::assertResponseIsSuccessful();

        /** @var array{token_type: string, expires_in: int, access_token: string, refresh_token: string} */
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function authorizeCode(): string
    {
        $url = '/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => OAuth2Config::SCOPE_FULL,
            'state' => 'rotation-state',
            'code_challenge' => $this->challenge,
            'code_challenge_method' => 'S256',
        ]);

        $crawler = $this->client->request('GET', $url);

        if (Response::HTTP_FOUND !== $this->client->getResponse()->getStatusCode()) {
            $csrf = $crawler->filter('#oauth-consent-form input[name="_csrf_token"]')->attr('value');
            self::assertNotNull($csrf);
            $this->client->request('POST', $url, ['decision' => 'allow', '_csrf_token' => $csrf]);
        }

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertArrayHasKey('code', $query);
        self::assertIsString($query['code']);

        return $query['code'];
    }

    /**
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    private function refresh(string $refreshToken): array
    {
        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $refreshToken,
        ]);

        /** @var array{token_type: string, expires_in: int, access_token: string, refresh_token: string} */
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function registerPublicClient(): void
    {
        $manager = static::getContainer()->get(ClientManagerInterface::class);

        $client = new Client('PaySubscriptions CLI', self::CLIENT_ID, null);
        $client->setRedirectUris(new RedirectUri(self::REGISTERED_REDIRECT));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(new Scope(OAuth2Config::SCOPE_FULL));
        $manager->save($client);
    }
}

final class RotationTestScope implements ScopeEntityInterface
{
    public function __construct(private readonly string $identifier)
    {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function jsonSerialize(): string
    {
        return $this->identifier;
    }
}
