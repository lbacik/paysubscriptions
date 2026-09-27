<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\OAuth2\ApiAccessTokenEntity;
use App\OAuth2\OAuth2Config;
use App\Tests\DatabaseTestCase;
use DateTimeImmutable;
use League\Bundle\OAuth2ServerBundle\Entity\Client as ClientEntity;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * API firewall enforcement for User-delegated OAuth2 tokens (issue #88).
 *
 * The /api entrypoint is the probe: no token or a forged token must stop at
 * the firewall with 401, while a properly issued token reaches the resource.
 * Hand-crafted tokens go through the production ApiAccessTokenEntity so the
 * tests sign exactly the same JWT shape the token endpoint issues.
 */
final class OAuthApiFirewallTest extends DatabaseTestCase
{
    private const CLIENT_ID = 'paysubs-cli';
    private const REDIRECT_URI = 'http://127.0.0.1:54123/callback';
    private const ISSUER = 'http://localhost';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('api-user@example.com', 'Fixture-Password-1', true);
    }

    public function testApiWithoutTokenIsUnauthorized(): void
    {
        $this->client->request('GET', '/api');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testIssuedTokenOpensApi(): void
    {
        $this->registerPublicClient();
        $this->client->loginUser($this->user);

        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $url = '/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => OAuth2Config::SCOPE_FULL,
            'state' => 'firewall-state',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $crawler = $this->client->request('GET', $url);
        $csrf = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', $url, ['decision' => 'allow', '_csrf_token' => $csrf]);
        parse_str(
            (string) parse_url((string) $this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY),
            $query
        );

        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $query['code'],
            'code_verifier' => $verifier,
        ]);
        self::assertResponseIsSuccessful();
        $token = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->client->request('GET', '/api', [], [], ['HTTP_Authorization' => 'Bearer '.$token['access_token']]);

        self::assertResponseIsSuccessful();
    }

    public function testApiWithValidCraftedTokenReachesEntrypoint(): void
    {
        $this->client->request('GET', '/api', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken(),
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testApiWithUserlessTokenIsUnauthorized(): void
    {
        // No User subject: the token falls back to the client identifier,
        // which no User account matches.
        $this->client->request('GET', '/api', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken(userIdentifier: null),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testApiWithWrongAudienceTokenIsDenied(): void
    {
        $this->client->request('GET', '/api', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken(audience: 'urn:example:other'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testApiWithWrongIssuerIsDenied(): void
    {
        $this->client->request('GET', '/api', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken(issuer: 'https://evil.example'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testApiWithExpiredTokenIsDenied(): void
    {
        $this->client->request('GET', '/api', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken(expiry: new DateTimeImmutable('-1 hour')),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testApiWithTamperedSignatureIsDenied(): void
    {
        $token = $this->craftToken();
        $tampered = substr($token, 0, -1).('A' === substr($token, -1) ? 'B' : 'A');

        $this->client->request('GET', '/api', [], [], ['HTTP_Authorization' => 'Bearer '.$tampered]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    private function craftToken(
        string $issuer = self::ISSUER,
        string $audience = OAuth2Config::API_AUDIENCE,
        ?string $userIdentifier = 'api-user@example.com',
        ?DateTimeImmutable $expiry = null,
    ): string {
        $entity = new ApiAccessTokenEntity($issuer, $audience);
        $entity->setIdentifier(bin2hex(random_bytes(16)));
        $clientEntity = new ClientEntity();
        $clientEntity->setIdentifier(self::CLIENT_ID);
        $clientEntity->setName('PaySubscriptions CLI');
        $entity->setClient($clientEntity);
        if (null !== $userIdentifier) {
            $entity->setUserIdentifier($userIdentifier);
        }
        $entity->addScope(new FirewallTestScope(OAuth2Config::SCOPE_FULL));
        $entity->setExpiryDateTime($expiry ?? new DateTimeImmutable('+15 minutes'));
        $entity->setPrivateKey(new CryptKey($this->privateKeyPath()));

        return $entity->toString();
    }

    private function registerPublicClient(): void
    {
        $manager = static::getContainer()->get(ClientManagerInterface::class);

        $client = new Client('PaySubscriptions CLI', self::CLIENT_ID, null);
        $client->setRedirectUris(new RedirectUri('http://127.0.0.1/callback'));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(new Scope(OAuth2Config::SCOPE_FULL));
        $manager->save($client);
    }

    private function privateKeyPath(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir').'/tests/Fixtures/oauth/private.pem';
    }
}

final class FirewallTestScope implements ScopeEntityInterface
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
