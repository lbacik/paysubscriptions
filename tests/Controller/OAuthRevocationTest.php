<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\OAuth2\OAuth2Config;
use App\Tests\DatabaseTestCase;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Component\HttpFoundation\Response;

/**
 * User disconnect and client-facing RFC 7009 revocation (issue #92).
 *
 * A User sees their approved clients and revokes each grant; a later
 * authorization asks for consent again and refresh fails. A client revokes
 * its refresh token through the authenticated revocation endpoint, which
 * retires the whole usable family but never claims to revoke self-contained
 * access tokens: already-issued access stays usable until its 15-minute
 * expiry.
 */
final class OAuthRevocationTest extends DatabaseTestCase
{
    private const CLIENT_ID = 'paysubs-cli';
    private const CLIENT_NAME = 'PaySubscriptions CLI';
    private const OTHER_CLIENT_ID = 'other-cli';
    private const REGISTERED_REDIRECT = 'http://127.0.0.1/callback';
    private const REDIRECT_URI = 'http://127.0.0.1:54123/callback';

    private User $user;
    private string $verifier;
    private string $challenge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('revoke-user@example.com', 'Fixture-Password-1', true);
        $this->registerPublicClient();

        $this->verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $this->challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    }

    public function testConnectedAppsListShowsApprovedClient(): void
    {
        $this->client->loginUser($this->user);
        $this->authorizeAndExchange();

        $crawler = $this->client->request('GET', '/profile/connected-apps');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::CLIENT_NAME, $crawler->text(null, true));
        self::assertStringContainsString(self::CLIENT_ID, $crawler->text(null, true));
    }

    public function testConnectedAppsPageStatesResidualAccessWindow(): void
    {
        $this->client->loginUser($this->user);
        $this->authorizeAndExchange();

        $crawler = $this->client->request('GET', '/profile/connected-apps');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('15 minutes', $crawler->text(null, true));
    }

    public function testAnonymousUserIsSentThroughWebLogin(): void
    {
        $this->client->request('GET', '/profile/connected-apps');

        self::assertResponseRedirects('/login');
    }

    public function testRevokeDisconnectAsksConsentAgain(): void
    {
        $this->client->loginUser($this->user);
        $this->authorizeAndExchange();

        $this->revokeGrant(self::CLIENT_ID);

        // The remembered grant is gone: authorizing again shows the screen.
        $this->client->request('GET', $this->authorizeUrl());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::CLIENT_NAME, (string) $this->client->getResponse()->getContent());
    }

    public function testRefreshFailsAfterDisconnect(): void
    {
        $this->client->loginUser($this->user);
        $tokens = $this->authorizeAndExchange();

        $this->revokeGrant(self::CLIENT_ID);

        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $tokens['refresh_token'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());
    }

    public function testFreshAuthorizationWorksAfterDisconnect(): void
    {
        $this->client->loginUser($this->user);
        $tokens = $this->authorizeAndExchange();
        $this->revokeGrant(self::CLIENT_ID);

        // Renewed consent starts a new family that refreshes fine.
        $fresh = $this->authorizeAndExchange();
        self::assertNotSame($tokens['refresh_token'], $fresh['refresh_token']);

        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $fresh['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testAccessTokenStaysUsableAfterDisconnect(): void
    {
        $this->client->loginUser($this->user);
        $tokens = $this->authorizeAndExchange();

        $this->revokeGrant(self::CLIENT_ID);

        // Ordinary disconnect retires refresh families immediately, but the
        // already-issued self-contained access token works until expiry.
        $this->client->request('GET', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$tokens['access_token'],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testRevokeOnlyRemovesOwnGrant(): void
    {
        $other = $this->createUser('revoke-other@example.com', 'Fixture-Password-1', true);
        $this->registerPublicClient(self::OTHER_CLIENT_ID, 'Other CLI');

        $this->client->loginUser($this->user);
        $this->authorizeAndExchange();

        $this->client->loginUser($other);
        $otherTokens = $this->authorizeAndExchange(self::OTHER_CLIENT_ID);

        // Revoking the first User's grant leaves the other User untouched…
        $this->client->loginUser($this->user);
        $this->revokeGrant(self::CLIENT_ID);

        // …and revoking an unknown grant changes nothing for the other User.
        $this->revokeGrant(self::OTHER_CLIENT_ID);

        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::OTHER_CLIENT_ID,
            'refresh_token' => $otherTokens['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testClientRevocationRetiresWholeFamily(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();
        $second = $this->refresh($first['refresh_token']);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/revoke', [
            'token' => $second['refresh_token'],
            'token_type_hint' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
        ]);
        self::assertResponseIsSuccessful();

        // The presented token is dead…
        $this->refresh($second['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());

        // …and so is every other usable token of its family.
        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testClientRevocationAsksConsentAgain(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        $this->client->request('POST', '/revoke', [
            'token' => $first['refresh_token'],
            'client_id' => self::CLIENT_ID,
        ]);
        self::assertResponseIsSuccessful();

        // The remembered consent is forgotten with the family: the next
        // authorization shows the screen again instead of auto-approving.
        $this->client->request('GET', $this->authorizeUrl());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::CLIENT_NAME, (string) $this->client->getResponse()->getContent());

        // Renewed consent mints a working family.
        $fresh = $this->authorizeAndExchange();
        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $fresh['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testConsentScreenListsOtherApprovedClients(): void
    {
        $this->registerPublicClient(self::OTHER_CLIENT_ID, 'Other CLI');
        $this->client->loginUser($this->user);
        $this->authorizeAndExchange();

        // Authorizing another client shows the already-approved one beside
        // the new request.
        $crawler = $this->client->request('GET', $this->authorizeUrl(self::OTHER_CLIENT_ID));

        self::assertResponseIsSuccessful();
        $approved = $crawler->filter('#approved-clients li');
        self::assertCount(1, $approved);
        self::assertStringContainsString(self::CLIENT_NAME, $approved->text(null, true));
    }

    public function testRevokeUnknownTokenStillSucceeds(): void
    {
        $this->client->request('POST', '/revoke', [
            'token' => 'not-a-refresh-token',
            'client_id' => self::CLIENT_ID,
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testRevokeWithoutTokenFails(): void
    {
        $this->client->request('POST', '/revoke', [
            'client_id' => self::CLIENT_ID,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_request', (string) $this->client->getResponse()->getContent());
    }

    public function testRevokeWithUnknownClientFails(): void
    {
        $this->client->request('POST', '/revoke', [
            'token' => 'not-a-refresh-token',
            'client_id' => 'unknown-cli',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertStringContainsString('invalid_client', (string) $this->client->getResponse()->getContent());
    }

    public function testRevokeWithWrongClientKeepsTokenUsable(): void
    {
        $this->registerPublicClient(self::OTHER_CLIENT_ID, 'Other CLI');
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        $this->client->request('POST', '/revoke', [
            'token' => $first['refresh_token'],
            'client_id' => self::OTHER_CLIENT_ID,
        ]);
        self::assertResponseIsSuccessful();

        $second = $this->refresh($first['refresh_token']);
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('access_token', $second);
    }

    public function testRevokeAccessTokenHintKeepsAccessUsable(): void
    {
        $this->client->loginUser($this->user);
        $tokens = $this->authorizeAndExchange();

        // Self-contained access tokens cannot be revoked: the endpoint
        // succeeds without claiming revocation, and the token still opens
        // the API until its short expiry.
        $this->client->request('POST', '/revoke', [
            'token' => $tokens['access_token'],
            'token_type_hint' => 'access_token',
            'client_id' => self::CLIENT_ID,
        ]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$tokens['access_token'],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testConfidentialClientRevocationNeedsSecret(): void
    {
        $this->registerConfidentialClient();
        $this->client->loginUser($this->user);
        $tokens = $this->authorizeAndExchange('conf-cli');

        // Wrong secret: rejected as unauthorized, token untouched.
        $this->client->request('POST', '/revoke', [
            'token' => $tokens['refresh_token'],
            'client_id' => 'conf-cli',
            'client_secret' => 'wrong-secret',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertStringContainsString('invalid_client', (string) $this->client->getResponse()->getContent());

        $second = $this->refresh($tokens['refresh_token'], 'conf-cli', 'conf-secret');
        self::assertResponseIsSuccessful();

        // Correct secret: revoked.
        $this->client->request('POST', '/revoke', [
            'token' => $second['refresh_token'],
            'client_id' => 'conf-cli',
            'client_secret' => 'conf-secret',
        ]);
        self::assertResponseIsSuccessful();

        $this->refresh($second['refresh_token'], 'conf-cli', 'conf-secret');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    /**
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    private function authorizeAndExchange(string $clientId = self::CLIENT_ID): array
    {
        $url = $this->authorizeUrl($clientId);
        $crawler = $this->client->request('GET', $url);

        if (Response::HTTP_FOUND === $this->client->getResponse()->getStatusCode()) {
            $code = $this->codeFromRedirect();
        } else {
            $csrf = $crawler->filter('input[name="_csrf_token"]')->attr('value');
            self::assertNotNull($csrf);
            $this->client->request('POST', $url, ['decision' => 'allow', '_csrf_token' => $csrf]);
            $code = $this->codeFromRedirect();
        }

        $params = [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => $this->verifier,
        ];
        if ('conf-cli' === $clientId) {
            $params['client_secret'] = 'conf-secret';
        }

        $this->client->request('POST', '/token', $params);
        self::assertResponseIsSuccessful();

        /** @var array{token_type: string, expires_in: int, access_token: string, refresh_token: string} */
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    private function refresh(string $refreshToken, string $clientId = self::CLIENT_ID, ?string $secret = null): array
    {
        $params = [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
        ];
        if (null !== $secret) {
            $params['client_secret'] = $secret;
        }

        $this->client->request('POST', '/token', $params);

        /** @var array{token_type: string, expires_in: int, access_token: string, refresh_token: string} */
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function authorizeUrl(string $clientId = self::CLIENT_ID): string
    {
        return '/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => OAuth2Config::SCOPE_FULL,
            'state' => 'revoke-state',
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

    private function revokeGrant(string $clientId): void
    {
        $crawler = $this->client->request('GET', '/profile/connected-apps');
        self::assertResponseIsSuccessful();

        $csrf = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($csrf);

        $this->client->request('POST', '/profile/connected-apps/revoke', [
            'client_id' => $clientId,
            '_token' => $csrf,
        ]);
        self::assertResponseRedirects('/profile/connected-apps');
    }

    private function registerPublicClient(
        string $identifier = self::CLIENT_ID,
        string $name = self::CLIENT_NAME,
    ): void {
        $manager = static::getContainer()->get(ClientManagerInterface::class);

        $client = new Client($name, $identifier, null);
        $client->setRedirectUris(new RedirectUri(self::REGISTERED_REDIRECT));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(new Scope(OAuth2Config::SCOPE_FULL));
        $manager->save($client);
    }

    private function registerConfidentialClient(): void
    {
        $manager = static::getContainer()->get(ClientManagerInterface::class);
        $hasher = static::getContainer()->get('league.oauth2_server.password_hasher');

        $client = new Client('Confidential CLI', 'conf-cli', $hasher->hash('conf-secret'));
        $client->setRedirectUris(new RedirectUri(self::REGISTERED_REDIRECT));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(new Scope(OAuth2Config::SCOPE_FULL));
        $manager->save($client);
    }
}
