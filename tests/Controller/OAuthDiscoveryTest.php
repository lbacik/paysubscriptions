<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\OAuth2\OAuth2Config;
use App\Tests\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth2 discovery for the approved CLI (issue #90).
 *
 * Authorization Server Metadata (RFC 8414), Protected Resource Metadata
 * (RFC 9728), and the public JWKS are published at standards-based paths
 * outside the /api resource prefix. The documents advertise only implemented
 * capabilities (api:full scope, authorization_code + refresh_token grants,
 * S256 PKCE, public clients with no secret) and the RS document identifies
 * the PaySubscriptions API audience carried by every access token. Tests
 * compare advertised capabilities against the working endpoints.
 */
final class OAuthDiscoveryTest extends DatabaseTestCase
{
    private const ISSUER = 'http://localhost';

    public function testAuthorizationServerMetadata(): void
    {
        $this->client->request('GET', '/.well-known/oauth-authorization-server');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));

        /** @var array<string, mixed> $metadata */
        $metadata = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(self::ISSUER, $metadata['issuer']);
        self::assertSame(self::ISSUER.'/authorize', $metadata['authorization_endpoint']);
        self::assertSame(self::ISSUER.'/token', $metadata['token_endpoint']);
        self::assertSame(self::ISSUER.'/.well-known/jwks.json', $metadata['jwks_uri']);
        self::assertSame([OAuth2Config::SCOPE_FULL], $metadata['scopes_supported']);
        self::assertSame(['code'], $metadata['response_types_supported']);
        self::assertSame(['authorization_code', 'refresh_token'], $metadata['grant_types_supported']);
        self::assertSame(['S256'], $metadata['code_challenge_methods_supported']);
        self::assertContains('none', $metadata['token_endpoint_auth_methods_supported']);

        // Dynamic registration and device authorization are neither
        // implemented nor advertised. Revocation is implemented at POST
        // /revoke but deliberately not advertised, so clients call the
        // documented URL instead of discovering it.
        self::assertArrayNotHasKey('registration_endpoint', $metadata);
        self::assertArrayNotHasKey('revocation_endpoint', $metadata);
        self::assertArrayNotHasKey('device_authorization_endpoint', $metadata);
    }

    public function testProtectedResourceMetadata(): void
    {
        $this->client->request('GET', '/.well-known/oauth-protected-resource');

        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $metadata */
        $metadata = json_decode((string) $this->client->getResponse()->getContent(), true);

        // The resource identifier is the API audience every access token
        // carries, so the CLI can validate the `aud` claim.
        self::assertSame(OAuth2Config::API_AUDIENCE, $metadata['resource']);
        self::assertSame([self::ISSUER], $metadata['authorization_servers']);
        self::assertSame(self::ISSUER.'/.well-known/jwks.json', $metadata['jwks_uri']);
        self::assertSame([OAuth2Config::SCOPE_FULL], $metadata['scopes_supported']);
        self::assertContains('header', $metadata['bearer_methods_supported']);
    }

    public function testProtectedResourceMetadataPathVariantMatches(): void
    {
        $this->client->request('GET', '/.well-known/oauth-protected-resource/api/v1');
        self::assertResponseIsSuccessful();
        $variant = (string) $this->client->getResponse()->getContent();

        $this->client->request('GET', '/.well-known/oauth-protected-resource');
        self::assertResponseIsSuccessful();

        self::assertSame((string) $this->client->getResponse()->getContent(), $variant);
    }

    public function testJwksPublishesOnlyPublicVerificationMaterial(): void
    {
        $this->client->request('GET', '/.well-known/jwks.json');

        self::assertResponseIsSuccessful();

        /** @var array{keys: list<array<string, mixed>>} $jwks */
        $jwks = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertCount(1, $jwks['keys']);
        $key = $jwks['keys'][0];

        self::assertSame('RSA', $key['kty']);
        self::assertSame('RS256', $key['alg']);
        self::assertSame('sig', $key['use']);
        self::assertArrayHasKey('n', $key);
        self::assertArrayHasKey('e', $key);

        foreach (['d', 'p', 'q', 'dp', 'dq', 'qi', 'k'] as $privateField) {
            self::assertArrayNotHasKey($privateField, $key);
        }

        // The advertised key is the verification key the resource server uses.
        $details = openssl_pkey_get_details(
            (false !== ($handle = openssl_pkey_get_public((string) file_get_contents($this->publicKeyPath()))))
                ? $handle
                : throw new \RuntimeException('Cannot read test public key')
        );
        self::assertNotFalse($details);
        self::assertSame(
            rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
            $key['n']
        );
        self::assertSame(
            rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
            $key['e']
        );
    }

    public function testAdvertisedTokenVerifiesAgainstAdvertisedJwks(): void
    {
        $this->client->request('GET', '/.well-known/jwks.json');
        /** @var array{keys: list<array<string, string>>} $jwks */
        $jwks = json_decode((string) $this->client->getResponse()->getContent(), true);

        $token = $this->fullAuthorizationCodeFlow();

        $config = \Lcobucci\JWT\Configuration::forAsymmetricSigner(
            new \Lcobucci\JWT\Signer\Rsa\Sha256(),
            \Lcobucci\JWT\Signer\Key\InMemory::plainText('unused-signing-key'),
            \Lcobucci\JWT\Signer\Key\InMemory::plainText($this->pemFromJwk($jwks['keys'][0]['n'], $jwks['keys'][0]['e']))
        );
        $parsed = $config->parser()->parse($token);

        self::assertTrue($config->validator()->validate(
            $parsed,
            new \Lcobucci\JWT\Validation\Constraint\SignedWith($config->signer(), $config->verificationKey())
        ));
        self::assertSame([OAuth2Config::API_AUDIENCE], $parsed->claims()->get('aud'));
    }

    public function testDiscoveryIsUsableByApprovedClientEndToEnd(): void
    {
        // 1. Discover.
        $this->client->request('GET', '/.well-known/oauth-authorization-server');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $as */
        $as = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->client->request('GET', '/.well-known/oauth-protected-resource');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $rs */
        $rs = json_decode((string) $this->client->getResponse()->getContent(), true);

        // 2. The advertised authorization endpoint guards anonymous Users
        // through the existing web login, like /authorize does.
        $authorizePath = (string) parse_url((string) $as['authorization_endpoint'], PHP_URL_PATH);
        $this->client->request('GET', $authorizePath.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => 'paysubs-cli',
            'redirect_uri' => 'http://127.0.0.1:54123/callback',
            'scope' => OAuth2Config::SCOPE_FULL,
            'state' => 'discovery-state',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
        ]));
        self::assertResponseRedirects('/login');

        // 3. The advertised token endpoint speaks the protocol: an
        // unimplemented grant fails with the protocol error, not a 404.
        $tokenPath = (string) parse_url((string) $as['token_endpoint'], PHP_URL_PATH);
        $this->client->request('POST', $tokenPath, ['grant_type' => 'client_credentials']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('unsupported_grant_type', (string) $this->client->getResponse()->getContent());

        // 4. A full flow through the advertised endpoints yields a token for
        // the advertised audience that opens the protected resource.
        $accessToken = $this->fullAuthorizationCodeFlow();
        $this->client->request('GET', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$accessToken,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame($rs['scopes_supported'], [OAuth2Config::SCOPE_FULL]);
    }

    public function testDeviceFlowIsOutsideV1(): void
    {
        // No device_authorization_endpoint is advertised, and the grant is
        // disabled: the route answers with a protocol error, never a code.
        $this->client->request('POST', '/device-code', ['client_id' => 'paysubs-cli']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString(
            'unsupported_grant_type',
            (string) $this->client->getResponse()->getContent()
        );
    }

    public function testDiscoveryLivesOutsideResourcePrefixAndStaysPublic(): void
    {
        foreach ([
            '/.well-known/oauth-authorization-server',
            '/.well-known/oauth-protected-resource',
            '/.well-known/jwks.json',
        ] as $path) {
            self::assertStringStartsNotWith('/api', $path);
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful();
        }

        // Unknown well-known documents still 404 instead of leaking.
        $this->client->request('GET', '/.well-known/oauth-authorization-server/extra-path');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function publicKeyPath(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir').'/tests/Fixtures/oauth/public.pem';
    }

    /**
     * Runs the documented CLI flow (register public client, web login,
     * consent, code exchange) and returns a working access token.
     */
    private function fullAuthorizationCodeFlow(): string
    {
        $user = $this->createUser('discovery-cli-user@example.com', 'Fixture-Password-1', true);

        $manager = static::getContainer()->get(\League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface::class);
        $client = new \League\Bundle\OAuth2ServerBundle\Model\Client('PaySubscriptions CLI', 'paysubs-cli', null);
        $client->setRedirectUris(new \League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri('http://127.0.0.1/callback'));
        $client->setGrants(
            new \League\Bundle\OAuth2ServerBundle\ValueObject\Grant('authorization_code'),
            new \League\Bundle\OAuth2ServerBundle\ValueObject\Grant('refresh_token')
        );
        $client->setScopes(new \League\Bundle\OAuth2ServerBundle\ValueObject\Scope(OAuth2Config::SCOPE_FULL));
        $manager->save($client);

        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $url = '/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => 'paysubs-cli',
            'redirect_uri' => 'http://127.0.0.1:54123/callback',
            'scope' => OAuth2Config::SCOPE_FULL,
            'state' => 'discovery-state',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', $url);
        $csrf = $crawler->filter('#oauth-consent-form input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', $url, ['decision' => 'allow', '_csrf_token' => $csrf]);

        parse_str(
            (string) parse_url((string) $this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY),
            $query
        );

        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'paysubs-cli',
            'redirect_uri' => 'http://127.0.0.1:54123/callback',
            'code' => $query['code'],
            'code_verifier' => $verifier,
        ]);
        self::assertResponseIsSuccessful();

        /** @var array{access_token: string} $token */
        $token = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $token['access_token'];
    }

    /**
     * Rebuilds a PEM RSA public key from JWK `n`/`e` (RFC 7518) so the test
     * verifies signatures with exactly the advertised material.
     */
    private function pemFromJwk(string $n, string $e): string
    {
        $modulus = $this->base64UrlDecode($n);
        $exponent = $this->base64UrlDecode($e);

        $encodeLength = static function (int $length): string {
            if ($length < 128) {
                return \chr($length);
            }
            $bytes = ltrim(pack('N', $length), "\x00");

            return \chr(0x80 | \strlen($bytes)).$bytes;
        };

        $encodeInt = static function (string $bytes) use ($encodeLength): string {
            if ("\x00" !== $bytes[0] && 0 !== (\ord($bytes[0]) & 0x80)) {
                $bytes = "\x00".$bytes;
            }

            return "\x02".$encodeLength(\strlen($bytes)).$bytes;
        };

        $rsaKey = "\x30".$encodeLength(\strlen($encodeInt($modulus).$encodeInt($exponent)))
            .$encodeInt($modulus).$encodeInt($exponent);
        $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $bitString = "\x03".$encodeLength(\strlen($rsaKey) + 1)."\x00".$rsaKey;
        $sequence = "\x30".$encodeLength(\strlen($algorithm.$bitString)).$algorithm.$bitString;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($sequence), 64, "\n").'-----END PUBLIC KEY-----';
    }

    private function base64UrlDecode(string $input): string
    {
        $padded = strtr($input, '-_', '+/');
        $padded .= str_repeat('=', (4 - \strlen($padded) % 4) % 4);

        $decoded = base64_decode($padded, true);
        self::assertNotFalse($decoded);

        return $decoded;
    }
}
