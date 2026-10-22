<?php

declare(strict_types=1);

namespace App\Tests\Api\V1;

use App\OAuth2\OAuth2Config;
use App\Entity\User;
use DateTimeImmutable;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The API v1 token gate, tested once against one endpoint: the api firewall
 * validates the bearer token and ApiAccessListener enforces the `api:full`
 * scope and ClientApprovalPolicy for every /api/v1 route, so resource suites
 * do not repeat this matrix.
 *
 * Failures are problems: 401s render as the generic `/errors/401` (with a
 * `Bearer` challenge), 403s as `/problems/insufficient-scope` (with the scope
 * challenge) or `/problems/account-inactive`.
 */
final class TokenGateTest extends ApiV1TestCase
{
    private const URL = '/api/v1/expense-categories';
    private const EMAIL = 'api-gate-user@example.com';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser(self::EMAIL);
    }

    public function testValidTokenOpensTheApi(): void
    {
        $this->requestAs($this->user, 'GET', self::URL);

        self::assertResponseIsSuccessful();
    }

    public function testMissingTokenIsUnauthorized(): void
    {
        $this->client->request('GET', self::URL);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        self::assertJsonContains(['type' => '/errors/401', 'status' => 401]);
        self::assertArrayNotHasKey('code', $this->decodedBody());
    }

    public function testTamperedTokenIsUnauthorized(): void
    {
        $token = $this->craftToken(self::EMAIL);
        $tampered = substr($token, 0, -1).('A' === substr($token, -1) ? 'B' : 'A');

        $this->client->request('GET', self::URL, ['auth_bearer' => $tampered]);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        self::assertJsonContains(['type' => '/errors/401', 'status' => 401]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function rejectedClaims(): iterable
    {
        yield 'expired' => [['expiry' => new DateTimeImmutable('-1 hour')]];
        yield 'wrong audience' => [['audience' => 'urn:example:other']];
        yield 'wrong issuer' => [['issuer' => 'https://evil.example']];
        yield 'unknown client' => [['clientId' => 'unknown-cli']];
        yield 'no User' => [['userIdentifier' => null]];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('rejectedClaims')]
    public function testTokenWithRejectedClaimIsUnauthorized(array $claims): void
    {
        $this->client->request('GET', self::URL, ['auth_bearer' => $this->craftToken(...($claims + ['userIdentifier' => self::EMAIL]))]);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertJsonContains(['type' => '/errors/401', 'status' => 401]);
    }

    public function testTokenWithoutFullScopeIsForbidden(): void
    {
        $this->client->request('GET', self::URL, ['auth_bearer' => $this->craftToken(self::EMAIL, scope: 'api:read')]);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="api:full"');
        self::assertJsonContains([
            'type' => '/problems/insufficient-scope',
            'title' => 'Forbidden',
            'status' => 403,
            'detail' => 'This resource requires the "api:full" scope.',
        ]);
        self::assertArrayNotHasKey('code', $this->decodedBody());
    }

    public function testClientNarrowedAfterIssuanceIsForbidden(): void
    {
        // The token itself still carries api:full, but the client record no
        // longer approves it.
        $manager = static::getContainer()->get(ClientManagerInterface::class);
        $client = $manager->find(self::CLIENT_ID);
        self::assertNotNull($client);
        $client->setScopes(new Scope('api:limited'));
        $manager->save($client);

        $this->requestAs($this->user, 'GET', self::URL);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="api:full"');
        self::assertJsonContains(['type' => '/problems/insufficient-scope', 'status' => 403]);
    }

    public function testClientDeactivatedAfterIssuanceIsUnauthorized(): void
    {
        $manager = static::getContainer()->get(ClientManagerInterface::class);
        $client = $manager->find(self::CLIENT_ID);
        self::assertNotNull($client);
        $client->setActive(false);
        $manager->save($client);

        $this->requestAs($this->user, 'GET', self::URL);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer error="invalid_token"');
        self::assertJsonContains(['type' => '/errors/401', 'status' => 401]);
    }

    public function testUnverifiedUserIsForbidden(): void
    {
        $unverified = $this->createUser('api-gate-unverified@example.com', verified: false);

        $this->requestAs($unverified, 'GET', self::URL);

        self::assertResponseStatusCodeSame(403);
        self::assertJsonContains([
            'type' => '/problems/account-inactive',
            'title' => 'Forbidden',
            'status' => 403,
            'detail' => 'This account is not eligible to use the API.',
        ]);
        self::assertArrayNotHasKey('code', $this->decodedBody());
    }

    public function testUserDeletedAfterIssuanceIsUnauthorized(): void
    {
        $token = $this->craftToken(self::EMAIL);
        $this->em->remove($this->user);
        $this->em->flush();
        $this->em->clear();

        $this->client->request('GET', self::URL, ['auth_bearer' => $token]);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertJsonContains(['type' => '/errors/401', 'status' => 401]);
    }

    public function testTokenIssuedThroughTheOAuth2FlowOpensTheApi(): void
    {
        // The one end-to-end check that minted tokens match issued ones:
        // authorize, consent and exchange the code with PKCE.
        $browser = $this->client->getKernelBrowser();
        $browser->loginUser($this->user);

        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $redirectUri = 'http://127.0.0.1:54123/callback';
        $url = '/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => $redirectUri,
            'scope' => OAuth2Config::SCOPE_FULL,
            'state' => 'api-gate-state',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $crawler = $browser->request('GET', $url);
        $csrf = $crawler->filter('#oauth-consent-form input[name="_csrf_token"]')->attr('value');
        $browser->request('POST', $url, ['decision' => 'allow', '_csrf_token' => $csrf]);
        parse_str((string) parse_url((string) $browser->getResponse()->headers->get('Location'), PHP_URL_QUERY), $query);

        $browser->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => $redirectUri,
            'code' => $query['code'],
            'code_verifier' => $verifier,
        ]);
        self::assertResponseIsSuccessful();
        $token = json_decode((string) $browser->getResponse()->getContent(), true);

        $this->client->request('GET', self::URL, ['auth_bearer' => $token['access_token']]);

        self::assertResponseIsSuccessful();
    }

    public function testWebLoginIsUnaffectedByTheApiFirewall(): void
    {
        $browser = $this->client->getKernelBrowser();
        $crawler = $browser->request('GET', '/login');
        $browser->request('POST', '/login', [
            'email' => self::EMAIL,
            'password' => 'Fixture-Password-1',
            '_csrf_token' => $crawler->filter('input[name="_csrf_token"]')->attr('value'),
        ]);
        $browser->request('GET', '/category');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/html', (string) $browser->getResponse()->headers->get('Content-Type'));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedBody(): array
    {
        $response = $this->client->getResponse();
        self::assertNotNull($response);

        return $response->toArray(false);
    }
}
