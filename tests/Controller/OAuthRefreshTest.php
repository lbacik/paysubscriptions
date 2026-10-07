<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\OAuthRefreshFamily;
use App\Entity\User;
use App\OAuth2\OAuth2Config;
use App\OAuth2\RefreshTokenFamilyRepository;
use App\Tests\DatabaseTestCase;
use DateInterval;
use DateTimeImmutable;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use SortDirection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Refresh-token rotation with single-use families (issue #91).
 *
 * An approved authorization-code client continues the User session by
 * exchanging its refresh token: every successful refresh rotates (the prior
 * token becomes unusable), reuse of a rotated token revokes the whole family,
 * families expire after 30 days without use and no later than 90 days after
 * initial authorization, and wrong-client/wrong-resource/expired/replayed and
 * otherwise invalid requests fail with protocol-defined errors.
 */
final class OAuthRefreshTest extends DatabaseTestCase
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

        $this->user = $this->createUser('refresh-user@example.com', 'Fixture-Password-1', true);
        $this->registerPublicClient();

        $this->verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $this->challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    }

    public function testRefreshRotatesAndPriorTokenBecomesUnusable(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        $second = $this->refresh($first['refresh_token']);
        self::assertResponseIsSuccessful();
        self::assertNotSame($first['refresh_token'], $second['refresh_token']);
        self::assertNotSame($first['access_token'], $second['access_token']);

        // The rotated-out token is single-use: replaying it fails.
        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());
    }

    public function testRefreshedAccessTokenKeepsUserAudienceClientAndScope(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();
        $second = $this->refresh($first['refresh_token']);
        self::assertResponseIsSuccessful();

        $claims = $this->parseClaims($second['access_token']);
        self::assertSame('refresh-user@example.com', $claims['sub']);
        self::assertSame([OAuth2Config::API_AUDIENCE], $claims['aud']);
        self::assertSame('http://localhost', $claims['iss']);
        self::assertSame(self::CLIENT_ID, $claims['client_id']);
        self::assertSame([OAuth2Config::SCOPE_FULL], $claims['scopes']);
        self::assertSame(900, $claims['exp'] - $claims['iat']);
    }

    public function testReuseOfRotatedTokenRevokesFamily(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();
        $second = $this->refresh($first['refresh_token']);
        self::assertResponseIsSuccessful();

        // Reuse the rotated token: rejected…
        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // …and the family's active token is revoked with it: fresh authorization required.
        $this->refresh($second['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());
    }

    public function testFreshAuthorizationWorksAfterFamilyRevocation(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();
        $second = $this->refresh($first['refresh_token']);
        self::assertResponseIsSuccessful();
        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // A brand-new authorization starts a new family and refreshes fine.
        $third = $this->authorizeAndExchange();
        $fourth = $this->refresh($third['refresh_token']);
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('access_token', $fourth);
        self::assertNotSame($second['refresh_token'], $fourth['refresh_token']);
    }

    public function testWrongClientIsRejected(): void
    {
        $this->registerPublicClient(self::OTHER_CLIENT_ID, 'Other CLI');
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::OTHER_CLIENT_ID,
            'refresh_token' => $first['refresh_token'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());
    }

    public function testDeactivatedClientIsRejectedAndFamilyRevoked(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        $manager = static::getContainer()->get(ClientManagerInterface::class);
        $client = $manager->find(self::CLIENT_ID);
        self::assertNotNull($client);
        $client->setActive(false);
        $manager->save($client);

        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());

        // Fail-closed: the family is revoked, so no later use can succeed.
        $family = $this->newestFamily();
        self::assertNotNull($family);
        self::assertTrue($family->isRevoked());
    }

    public function testUnverifiedUserIsRejectedAndFamilyRevoked(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        $em = $this->freshEm();
        $stored = $em->getRepository(User::class)->findOneBy(['email' => 'refresh-user@example.com']);
        self::assertNotNull($stored);
        $stored->setVerified(false);
        $em->flush();
        $em->clear();

        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());

        // Fail-closed: the family is revoked, so no later use can succeed.
        $family = $this->newestFamily();
        self::assertNotNull($family);
        self::assertTrue($family->isRevoked());
    }

    public function testNarrowedClientIsRejectedAndFamilyRevoked(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        $manager = static::getContainer()->get(ClientManagerInterface::class);
        $client = $manager->find(self::CLIENT_ID);
        self::assertNotNull($client);
        $client->setScopes(new Scope('api:limited'));
        $manager->save($client);

        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());

        // Fail-closed: the family is revoked, so no later use can succeed.
        $family = $this->newestFamily();
        self::assertNotNull($family);
        self::assertTrue($family->isRevoked());
    }

    public function testWrongResourceIsRejected(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $first['refresh_token'],
            'resource' => 'urn:example:other',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_target', (string) $this->client->getResponse()->getContent());
    }

    public function testWrongResourceInQueryStringIsRejected(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        $this->client->request('POST', '/token?'.http_build_query(['resource' => 'urn:example:other']), [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $first['refresh_token'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_target', (string) $this->client->getResponse()->getContent());
    }

    public function testRepeatedResourceIsRejected(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $first['refresh_token'],
            'resource' => [OAuth2Config::API_AUDIENCE, OAuth2Config::API_AUDIENCE],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_target', (string) $this->client->getResponse()->getContent());
    }

    public function testMatchingResourceIsAccepted(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $first['refresh_token'],
            'resource' => OAuth2Config::API_AUDIENCE,
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testExpiredRefreshTokenIsRejected(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();
        // A token untouched for 60 days is past the 30-day idle expiry: the
        // family rejects it even though its encrypted payload is unexpired.
        $this->ageFamily(lastUsedAgo: new DateInterval('P60D'), issuedAgo: new DateInterval('P60D'));

        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());
    }

    public function testInvalidRefreshTokenIsRejected(): void
    {
        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => 'not-a-refresh-token',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());
    }

    public function testUnknownScopeOnRefreshIsRejected(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $first['refresh_token'],
            'scope' => 'api:limited',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertTrue(
            str_contains($content, 'invalid_scope') || str_contains($content, 'invalid_grant'),
            'Unknown scope must fail, got: '.$content
        );
    }

    public function testIdleFamilyExpiresAfterThirtyDaysWithoutUse(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();
        // 31 days without use: the family is stale.
        $this->ageFamily(lastUsedAgo: new DateInterval('P31D'), issuedAgo: new DateInterval('P31D'));

        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());
    }

    public function testIdleBoundaryJustUnderThirtyDaysStillRefreshes(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();
        // 29 days without use: still within the 30-day idle window.
        $this->ageFamily(lastUsedAgo: new DateInterval('P29D'), issuedAgo: new DateInterval('P29D'));

        $second = $this->refresh($first['refresh_token']);
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('access_token', $second);
    }

    public function testIdleBoundaryAtThirtyDaysIsExpired(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();
        // Exactly 30 days without use: the idle window is over (time only
        // moves forward from here, so the check is not flaky).
        $this->ageFamily(lastUsedAgo: new DateInterval('P30D'), issuedAgo: new DateInterval('P30D'));

        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());
    }

    public function testAbsoluteFamilyExpiryAfterNinetyDays(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();
        // Initial authorization 91 days ago: past the absolute deadline.
        $this->ageFamily(lastUsedAgo: new DateInterval('PT1H'), issuedAgo: new DateInterval('P91D'));

        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());
    }

    public function testAbsoluteBoundaryAtNinetyDaysIsExpired(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();
        // Exactly 90 days after initial authorization: the absolute deadline
        // is reached (time only moves forward, so the check is not flaky).
        $this->ageFamily(lastUsedAgo: new DateInterval('PT1H'), issuedAgo: new DateInterval('P90D'));

        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());
    }

    public function testRefreshCannotExtendAbsoluteDeadline(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();
        // Initial authorization 89 days ago: one refresh left, but the new
        // token must be capped at the 90-day absolute deadline…
        $this->ageFamily(lastUsedAgo: new DateInterval('PT1H'), issuedAgo: new DateInterval('P89D'));

        $second = $this->refresh($first['refresh_token']);
        self::assertResponseIsSuccessful();

        $family = $this->newestFamily();
        self::assertNotNull($family);
        $remaining = $family->getAbsoluteExpiresAt()->getTimestamp() - time();
        // …about one day of life left, not a fresh 30 days.
        self::assertGreaterThan(0, $remaining);
        self::assertLessThan(2 * 86400, $remaining);
    }

    public function testConcurrentReuseRevokesFamily(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        // Two back-to-back uses of the same token: the first rotates, the
        // second replays a superseded token and revokes the family.
        $second = $this->refresh($first['refresh_token']);
        self::assertResponseIsSuccessful();

        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $this->refresh($second['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());
    }

    public function testFailedRefreshDoesNotLeakRotationIntoNextAuthorization(): void
    {
        // Issue #135: the rotation handshake is scoped to one token request,
        // so a refresh that fails after validation cannot make the next
        // authorization-code exchange rotate the earlier family. Runs on one
        // container without reboot (long-running worker runtime).
        self::assertInstanceOf(
            ResetInterface::class,
            static::getContainer()->get(RefreshTokenFamilyRepository::class)
        );

        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->allFamilies());

        // One container for the rest of the test: stale handshake state would
        // otherwise be hidden by a reboot.
        $this->client->disableReboot();
        try {
            // Unknown scope is checked after League validates the old refresh
            // token, so isRefreshTokenRevoked() records handshake state but
            // persist is never reached.
            $this->client->request('POST', '/token', [
                'grant_type' => 'refresh_token',
                'client_id' => self::CLIENT_ID,
                'refresh_token' => $first['refresh_token'],
                'scope' => 'api:limited',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

            // Fresh authorization must start a NEW family, not rotate the
            // earlier one via stale state.
            $second = $this->authorizeAndExchange();
            self::assertResponseIsSuccessful();

            $families = $this->allFamilies();
            self::assertCount(2, $families, 'stale rotation handshake reused the earlier family');

            // The original refresh token still rotates its own family: the
            // failed request did not retire it.
            $third = $this->refresh($first['refresh_token']);
            self::assertResponseIsSuccessful();
            self::assertArrayHasKey('access_token', $third);
            self::assertNotSame($second['refresh_token'], $third['refresh_token']);
        } finally {
            $this->client->enableReboot();
        }
    }

    /**
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    private function authorizeAndExchange(): array
    {
        $url = $this->authorizeUrl();
        $crawler = $this->client->request('GET', $url);

        if (Response::HTTP_FOUND === $this->client->getResponse()->getStatusCode()) {
            $code = $this->codeFromRedirect();
        } else {
            $csrf = $crawler->filter('#oauth-consent-form input[name="_csrf_token"]')->attr('value');
            self::assertNotNull($csrf);
            $this->client->request('POST', $url, ['decision' => 'allow', '_csrf_token' => $csrf]);
            $code = $this->codeFromRedirect();
        }

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

    private function authorizeUrl(): string
    {
        return '/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => OAuth2Config::SCOPE_FULL,
            'state' => 'refresh-state',
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

    private function newestFamily(): ?OAuthRefreshFamily
    {
        $em = $this->freshEm();

        return $em->createQueryBuilder()
            ->select('f')
            ->from(OAuthRefreshFamily::class, 'f')
            ->orderBy('f.issuedAt', SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<OAuthRefreshFamily>
     */
    private function allFamilies(): array
    {
        $em = $this->freshEm();
        $em->clear();

        return $em->getRepository(OAuthRefreshFamily::class)->findAll();
    }

    private function ageFamily(DateInterval $lastUsedAgo, DateInterval $issuedAgo): void
    {
        $em = $this->freshEm();
        $family = $this->newestFamily();
        self::assertNotNull($family);
        $now = new DateTimeImmutable();
        $issuedAt = $now->sub($issuedAgo);
        $family->setIssuedAt($issuedAt);
        $family->setLastUsedAt($now->sub($lastUsedAgo));
        $family->setAbsoluteExpiresAt($issuedAt->add(new DateInterval('P'.OAuth2Config::REFRESH_ABSOLUTE_DAYS.'D')));
        $em->flush();
        $em->clear();
    }

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
}
