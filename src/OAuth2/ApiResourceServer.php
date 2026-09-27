<?php

declare(strict_types=1);

namespace App\OAuth2;

use DateInterval;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use League\OAuth2\Server\AuthorizationValidators\BearerTokenValidator;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Resource server that additionally enforces the token's issuer and API
 * audience (decision #86). Signature, expiry, and revocation-state checks
 * stay with League's bearer validator; this layer rejects tokens minted for
 * another issuer or another audience before any User ownership check runs.
 *
 * Signing-key rotation (issue #94): every access token carries the signing
 * key's identifier in its `kid` header (see ApiAccessTokenEntity). During a
 * routine rotation the operator publishes the previous public key alongside
 * the current one, and this server selects the verification key by `kid`:
 * current `kid` verifies against the current key, previous `kid` against the
 * previous key, an unknown `kid` fails closed. Tokens without a `kid`
 * (minted before key identifiers existed) are tried against the current key
 * first and then the previous one, so pre-rotation clients keep working until
 * their short-lived tokens expire. Dropping the previous key — the emergency
 * response to a suspected compromise — immediately invalidates every token
 * the old key signed.
 */
class ApiResourceServer extends ResourceServer
{
    private readonly BearerTokenValidator $currentValidator;

    private readonly ?BearerTokenValidator $previousValidator;

    private readonly string $keyId;

    private readonly string $previousKeyId;

    /**
     * @param CryptKeyInterface|string      $publicKey         current verification key (path or contents)
     * @param CryptKeyInterface|string|null $previousPublicKey previous verification key, if a rotation is in flight
     */
    public function __construct(
        AccessTokenRepositoryInterface $accessTokenRepository,
        CryptKeyInterface|string $publicKey,
        private readonly string $issuer,
        ?string $keyId = null,
        CryptKeyInterface|string|null $previousPublicKey = null,
        ?string $previousKeyId = null,
    ) {
        parent::__construct($accessTokenRepository, $publicKey);

        $this->keyId = $keyId ?? '';
        $this->previousKeyId = $previousKeyId ?? '';

        $this->currentValidator = new BearerTokenValidator(
            $accessTokenRepository,
            new DateInterval(OAuth2Config::CLOCK_SKEW_LEEWAY),
        );
        $this->currentValidator->setPublicKey(self::asPublicKey($publicKey));

        $previous = self::asPublicKeyOrNull($previousPublicKey);
        $this->previousValidator = null === $previous ? null : new BearerTokenValidator(
            $accessTokenRepository,
            new DateInterval(OAuth2Config::CLOCK_SKEW_LEEWAY),
        );
        if (null !== $previous && null !== $this->previousValidator) {
            $this->previousValidator->setPublicKey($previous);
        }
    }

    public function validateAuthenticatedRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        $validated = $this->validateWithSelectedKey($request);

        $clientId = $this->assertIssuerAndAudience($request);

        // Signature, expiry, and revocation were already verified above.
        // League's BearerTokenValidator treats `aud` as the client identifier,
        // but API v1 tokens carry the API audience there and the real client in
        // the `client_id` claim (see ApiAccessTokenEntity). Repair the PSR-7
        // attribute so downstream authentication sees the actual client.
        if (null !== $clientId && '' !== $clientId) {
            $validated = $validated->withAttribute('oauth_client_id', $clientId);
        }

        return $validated;
    }

    private function validateWithSelectedKey(ServerRequestInterface $request): ServerRequestInterface
    {
        $kid = $this->parseKeyId($request);

        if (null !== $kid) {
            $validator = $this->validatorForKeyId($kid);
            if (null === $validator) {
                // Unknown key identifier: fail closed without saying which
                // identifiers are configured.
                throw OAuthServerException::accessDenied('The access token could not be verified.');
            }

            return $validator->validateAuthorization($request);
        }

        // No `kid`: a token minted before key identifiers existed. Try the
        // current key first, then the previous one while a rotation is in
        // flight. Either failure surfaces as the usual 401.
        try {
            return $this->currentValidator->validateAuthorization($request);
        } catch (OAuthServerException $e) {
            if (null === $this->previousValidator) {
                throw $e;
            }

            return $this->previousValidator->validateAuthorization($request);
        }
    }

    private function validatorForKeyId(string $kid): ?BearerTokenValidator
    {
        if ('' !== $this->keyId && $kid === $this->keyId) {
            return $this->currentValidator;
        }

        if ('' !== $this->previousKeyId && $kid === $this->previousKeyId && null !== $this->previousValidator) {
            return $this->previousValidator;
        }

        return null;
    }

    /**
     * Reads the `kid` header without verifying the signature. Returns null
     * when the token is missing, unparsable, or carries no identifier: the
     * validators below then produce the protocol 401 for it.
     */
    private function parseKeyId(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('authorization');
        $jwt = trim((string) preg_replace('/^\s*Bearer\s/i', '', $header));
        if ('' === $jwt) {
            return null;
        }

        try {
            $token = (new Parser(new JoseEncoder()))->parse($jwt);
        } catch (Throwable) {
            return null;
        }

        if (!$token instanceof Plain) {
            return null;
        }

        $kid = $token->headers()->get('kid');

        return \is_string($kid) && '' !== $kid ? $kid : null;
    }

    /**
     * @return string|null the real client identifier from the `client_id` claim
     */
    private function assertIssuerAndAudience(ServerRequestInterface $request): ?string
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

        $clientId = $token->claims()->get('client_id');

        return \is_string($clientId) && '' !== $clientId ? $clientId : null;
    }

    private static function asPublicKey(CryptKeyInterface|string $key): CryptKeyInterface
    {
        if ($key instanceof CryptKeyInterface) {
            return $key;
        }

        // Public keys are world-readable by design, so the permission check
        // (meant for private keys) stays off, matching the
        // app.oauth2.resource_public_key service definition.
        return new CryptKey($key, null, false);
    }

    private static function asPublicKeyOrNull(CryptKeyInterface|string|null $key): ?CryptKeyInterface
    {
        if (null === $key) {
            return null;
        }

        if (\is_string($key) && '' === trim($key)) {
            return null;
        }

        return self::asPublicKey($key);
    }
}
