<?php

declare(strict_types=1);

namespace App\OAuth2;

use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Resource server that additionally enforces the token's issuer and API
 * audience (decision #86). Signature, expiry, and revocation-state checks
 * stay with the parent; this layer rejects tokens minted for another issuer
 * or another audience before any User ownership check runs.
 */
class ApiResourceServer extends ResourceServer
{
    public function __construct(
        AccessTokenRepositoryInterface $accessTokenRepository,
        CryptKeyInterface|string $publicKey,
        private readonly string $issuer,
    ) {
        parent::__construct($accessTokenRepository, $publicKey);
    }

    public function validateAuthenticatedRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        $validated = parent::validateAuthenticatedRequest($request);

        $this->assertIssuerAndAudience($request);

        return $validated;
    }

    private function assertIssuerAndAudience(ServerRequestInterface $request): void
    {
        $header = $request->getHeaderLine('authorization');
        $jwt = trim((string) preg_replace('/^\s*Bearer\s/i', '', $header));

        try {
            $token = (new Parser(new JoseEncoder()))->parse($jwt);
        } catch (Throwable) {
            throw OAuthServerException::accessDenied('The access token could not be parsed.');
        }

        if (!$token instanceof Plain) {
            throw OAuthServerException::accessDenied('The access token is not a valid JWT.');
        }

        $audiences = (array) $token->claims()->get('aud');
        if (!\in_array(OAuth2Config::API_AUDIENCE, $audiences, true)) {
            throw OAuthServerException::accessDenied('The access token audience is not the PaySubscriptions API.');
        }

        if ($token->claims()->get('iss') !== $this->issuer) {
            throw OAuthServerException::accessDenied('The access token issuer is not this authorization server.');
        }
    }

}
