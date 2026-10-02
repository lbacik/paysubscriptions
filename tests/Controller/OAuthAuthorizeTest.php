<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\OAuth2\OAuth2Config;
use App\Tests\DatabaseTestCase;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorization-code flow with S256 PKCE for a manually registered public CLI
 * (issue #88): existing web login, consent screen, allow/deny, remembered
 * consent, and protocol security (PKCE, redirect, scope, client type).
 */
final class OAuthAuthorizeTest extends DatabaseTestCase
{
    private const CLIENT_ID = 'paysubs-cli';
    private const CLIENT_NAME = 'PaySubscriptions CLI';
    private const REGISTERED_REDIRECT = 'http://127.0.0.1/callback';
    private const REDIRECT_URI = 'http://127.0.0.1:54123/callback';
    private const STATE = 'cli-state-1';

    private User $user;
    private string $verifier;
    private string $challenge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('cli-user@example.com', 'Fixture-Password-1', true);
        $this->registerPublicClient();

        $this->verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $this->challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    }

    public function testAnonymousUserIsSentThroughWebLogin(): void
    {
        $url = $this->authorizeUrl();

        $this->client->request('GET', $url);

        self::assertResponseRedirects('/login');
    }

    public function testWebLoginReturnsToAuthorizeAndShowsConsent(): void
    {
        $url = $this->authorizeUrl();

        $this->client->request('GET', $url);
        self::assertResponseRedirects('/login');

        $this->login('cli-user@example.com', 'Fixture-Password-1');
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::CLIENT_NAME, (string) $this->client->getResponse()->getContent());
    }

    public function testConsentScreenForbidsFraming(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('GET', $this->authorizeUrl());

        self::assertResponseIsSuccessful();
        self::assertSame(
            "frame-ancestors 'none'",
            $this->client->getResponse()->headers->get('Content-Security-Policy')
        );
        self::assertSame('DENY', $this->client->getResponse()->headers->get('X-Frame-Options'));
    }

    public function testSiteWideResponsesForbidFraming(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame(
            "frame-ancestors 'none'",
            $this->client->getResponse()->headers->get('Content-Security-Policy')
        );
    }
    public function testConsentScreenShowsClientIdentityAndFullAccess(): void
    {
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', $this->authorizeUrl());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::CLIENT_NAME, $crawler->text(null, true));
        self::assertStringContainsString(self::CLIENT_ID, $crawler->text(null, true));
        self::assertStringContainsString(OAuth2Config::SCOPE_FULL, $crawler->text(null, true));
        self::assertCount(1, $crawler->filter('button[value="allow"]'));
        self::assertCount(1, $crawler->filter('button[value="deny"]'));
    }

    public function testAllowIssuesCodeAndExchangesForToken(): void
    {
        $this->client->loginUser($this->user);

        $code = $this->allow($this->authorizeUrl());

        $token = $this->exchange($code);

        self::assertSame('Bearer', $token['token_type']);
        // expires_in is expiry minus "now", so it may lose a second when a clock boundary passes mid-request.
        self::assertEqualsWithDelta(900, $token['expires_in'], 2);
        self::assertArrayHasKey('access_token', $token);
        self::assertArrayHasKey('refresh_token', $token);
    }

    public function testIssuedTokenCarriesUserAudienceClientIssuerScopeAndExpiry(): void
    {
        $this->client->loginUser($this->user);

        $code = $this->allow($this->authorizeUrl());
        $token = $this->exchange($code);

        $claims = $this->parseClaims($token['access_token']);

        self::assertSame('cli-user@example.com', $claims['sub']);
        self::assertSame([OAuth2Config::API_AUDIENCE], $claims['aud']);
        self::assertSame('http://localhost', $claims['iss']);
        self::assertSame(self::CLIENT_ID, $claims['client_id']);
        self::assertSame([OAuth2Config::SCOPE_FULL], $claims['scopes']);
        self::assertSame(900, $claims['exp'] - $claims['iat']);
    }

    public function testAccessTokenSignatureVerifiesAgainstPublicKey(): void
    {
        $this->client->loginUser($this->user);

        $code = $this->allow($this->authorizeUrl());
        $token = $this->exchange($code);

        self::assertTrue($this->isSignatureValid($token['access_token']));
    }

    public function testDenyRedirectsWithAccessDenied(): void
    {
        $this->client->loginUser($this->user);

        $this->deny($this->authorizeUrl());

        $location = $this->redirectLocation();
        self::assertStringStartsWith(self::REDIRECT_URI, $location);
        self::assertStringContainsString('error=access_denied', $location);
        self::assertStringContainsString('state='.self::STATE, $location);
    }

    public function testRememberedConsentSkipsConsentScreen(): void
    {
        $this->client->loginUser($this->user);
        $url = $this->authorizeUrl();

        $this->allow($url);

        // Second authorization for the same client and scope needs no screen.
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertStringStartsWith(self::REDIRECT_URI, $this->redirectLocation());
        self::assertStringContainsString('code=', $this->redirectLocation());
    }

    public function testConsentAskedAgainWhenClientIdentityChanges(): void
    {
        $this->client->loginUser($this->user);
        $url = $this->authorizeUrl();

        $this->allow($url);
        $this->renameClient('Renamed CLI');

        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Renamed CLI', (string) $this->client->getResponse()->getContent());
    }

    public function testEmptyScopeRequestFails(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('GET', $this->authorizeUrl(['scope' => null]));

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertStringContainsString('error=invalid_scope', $this->redirectLocation());
    }

    public function testUnknownScopeRequestFails(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('GET', $this->authorizeUrl(['scope' => 'api:read']));

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertStringContainsString('error=invalid_scope', $this->redirectLocation());
    }

    public function testUnapprovedScopeRequestFails(): void
    {
        $this->client->loginUser($this->user);
        // A non-empty scope the client was never granted. (An empty scope list
        // cannot be used here: the bundle backfills scopeless clients with the
        // default scope, which would approve api:full instead of rejecting it.)
        $this->registerPublicClient('unapproved-cli', 'Unapproved CLI', ['api:limited']);

        $this->client->request('GET', $this->authorizeUrl(['client_id' => 'unapproved-cli']));

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertStringContainsString('error=invalid_scope', $this->redirectLocation());
    }

    public function testPlainPkceChallengeFails(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('GET', $this->authorizeUrl(['code_challenge_method' => 'plain']));

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testMissingChallengeFailsForPublicClient(): void
    {
        $this->client->loginUser($this->user);

        $url = $this->authorizeUrl();
        $url = (string) preg_replace('/&code_challenge=[^&]*/', '', $url);
        $url = (string) preg_replace('/&code_challenge_method=[^&]*/', '', $url);

        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testUnregisteredRedirectFails(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('GET', $this->authorizeUrl(['redirect_uri' => 'https://evil.example/callback']));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testWrongVerifierFailsAtToken(): void
    {
        $this->client->loginUser($this->user);

        $code = $this->allow($this->authorizeUrl());

        // Well-formed (RFC 7636) but wrong: flip the last verifier character.
        $wrongVerifier = substr($this->verifier, 0, -1).('A' === substr($this->verifier, -1) ? 'B' : 'A');

        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => $wrongVerifier,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());
    }

    public function testClientCredentialsGrantIsDisabled(): void
    {
        $this->client->request('POST', '/token', [
            'grant_type' => 'client_credentials',
            'client_id' => self::CLIENT_ID,
            'scope' => OAuth2Config::SCOPE_FULL,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString(
            'unsupported_grant_type',
            (string) $this->client->getResponse()->getContent()
        );
    }

    public function testConfidentialClientMustAuthenticateAtTokenEndpoint(): void
    {
        $this->registerConfidentialClient();
        $this->client->loginUser($this->user);

        $code = $this->allow($this->authorizeUrl(['client_id' => 'conf-cli']));

        // No secret: rejected as malformed.
        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'conf-cli',
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => $this->verifier,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_request', (string) $this->client->getResponse()->getContent());

        // Wrong secret: rejected as unauthorized. A fresh code is used for
        // every attempt because a failed exchange may consume the code.
        // Remembered consent now applies, so authorize() takes the 302 path.
        $code = $this->authorize($this->authorizeUrl(['client_id' => 'conf-cli']));
        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'conf-cli',
            'client_secret' => 'wrong-secret',
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => $this->verifier,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertStringContainsString('invalid_client', (string) $this->client->getResponse()->getContent());

        // Correct secret: accepted. A fresh code is needed because the failed
        // exchange already consumed the first one.
        $code = $this->authorize($this->authorizeUrl(['client_id' => 'conf-cli']));
        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'conf-cli',
            'client_secret' => 'conf-secret',
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => $this->verifier,
        ]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, string|null> $overrides
     */
    private function authorizeUrl(array $overrides = []): string
    {
        $params = array_merge([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => OAuth2Config::SCOPE_FULL,
            'state' => self::STATE,
            'code_challenge' => $this->challenge,
            'code_challenge_method' => 'S256',
        ], $overrides);

        $params = array_filter($params, static fn ($value): bool => null !== $value);

        return '/authorize?'.http_build_query($params);
    }

    private function allow(string $url): string
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $csrf = $crawler->filter('#oauth-consent-form input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($csrf);

        $this->client->request('POST', $url, [
            'decision' => 'allow',
            '_csrf_token' => $csrf,
        ]);

        return $this->codeFromRedirect();
    }

    /**
     * Like allow(), but tolerates remembered consent: when the GET already
     * redirects with a code, no consent screen is posted.
     */
    private function authorize(string $url): string
    {
        $this->client->request('GET', $url);

        if (Response::HTTP_FOUND === $this->client->getResponse()->getStatusCode()) {
            return $this->codeFromRedirect();
        }

        return $this->allow($url);
    }

    private function codeFromRedirect(): string
    {
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        $location = $this->redirectLocation();
        self::assertStringStartsWith(self::REDIRECT_URI, $location);
        self::assertStringContainsString('state='.self::STATE, $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertArrayHasKey('code', $query);
        self::assertIsString($query['code']);

        return $query['code'];
    }

    private function deny(string $url): void
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $csrf = $crawler->filter('#oauth-consent-form input[name="_csrf_token"]')->attr('value');

        $this->client->request('POST', $url, [
            'decision' => 'deny',
            '_csrf_token' => $csrf,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
    }

    /**
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    private function exchange(string $code, string $clientId = self::CLIENT_ID): array
    {
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

    private function redirectLocation(): string
    {
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($location);

        return $location;
    }

    /**
     * @return array{sub: string, aud: mixed, iss: string, client_id: string, scopes: list<string>, exp: int, iat: int}
     */
    private function parseClaims(string $jwt): array
    {
        $token = (new Parser(new JoseEncoder()))->parse($jwt);
        self::assertInstanceOf(Plain::class, $token);

        /** @var array{sub: string, aud: mixed, iss: string, client_id: string, scopes: list<string>, exp: int, iat: int} */
        return [
            'sub' => $token->claims()->get('sub'),
            'aud' => $token->claims()->get('aud'),
            'iss' => $token->claims()->get('iss'),
            'client_id' => $token->claims()->get('client_id'),
            'scopes' => $token->claims()->get('scopes'),
            'exp' => $token->claims()->get('exp')->getTimestamp(),
            'iat' => $token->claims()->get('iat')->getTimestamp(),
        ];
    }

    private function isSignatureValid(string $jwt): bool
    {
        $config = \Lcobucci\JWT\Configuration::forAsymmetricSigner(
            new \Lcobucci\JWT\Signer\Rsa\Sha256(),
            \Lcobucci\JWT\Signer\Key\InMemory::plainText('unused-signing-key'),
            \Lcobucci\JWT\Signer\Key\InMemory::file($this->publicKeyPath())
        );

        $token = $config->parser()->parse($jwt);

        return $config->validator()->validate(
            $token,
            new \Lcobucci\JWT\Validation\Constraint\SignedWith(
                $config->signer(),
                $config->verificationKey()
            )
        );
    }

    private function publicKeyPath(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir').'/tests/Fixtures/oauth/public.pem';
    }

    /**
     * @param list<string> $scopes
     */
    private function registerPublicClient(
        string $identifier = self::CLIENT_ID,
        string $name = self::CLIENT_NAME,
        array $scopes = [OAuth2Config::SCOPE_FULL],
    ): void {
        $manager = static::getContainer()->get(ClientManagerInterface::class);

        $client = new Client($name, $identifier, null);
        $client->setRedirectUris(new RedirectUri(self::REGISTERED_REDIRECT));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(...array_map(static fn (string $scope): Scope => new Scope($scope), $scopes));
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

    private function renameClient(string $name): void
    {
        $manager = static::getContainer()->get(ClientManagerInterface::class);
        $client = $manager->find(self::CLIENT_ID);
        self::assertNotNull($client);
        $client->setName($name);
        $manager->save($client);
    }
}
