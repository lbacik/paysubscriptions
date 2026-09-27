<?php

declare(strict_types=1);

namespace App\OAuth2;

use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Plain;
use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use RuntimeException;

/**
 * Access token JWT for the PaySubscriptions API (decisions #82, #86).
 *
 * Mirrors the League bearer-token shape but additionally carries the
 * authorization-server issuer and the PaySubscriptions API audience, plus the
 * client identifier: every token states who issued it, what API it opens, who
 * it was issued to (client), and whose data it opens (User subject).
 */
final class ApiAccessTokenEntity implements AccessTokenEntityInterface
{
    use EntityTrait;
    use TokenEntityTrait;

    private Configuration $jwtConfiguration;
    private CryptKeyInterface $privateKey;

    public function __construct(
        private readonly string $issuer,
        private readonly string $audience,
    ) {
    }

    public function setPrivateKey(CryptKeyInterface $privateKey): void
    {
        $this->privateKey = $privateKey;
    }

    public function toString(): string
    {
        return $this->convertToJWT()->toString();
    }

    private function convertToJWT(): Plain
    {
        $this->initJwtConfiguration();

        return $this->jwtConfiguration->builder()
            ->issuedBy($this->issuer)
            ->permittedFor($this->audience)
            ->identifiedBy($this->getIdentifier())
            ->issuedAt(new DateTimeImmutable())
            ->canOnlyBeUsedAfter(new DateTimeImmutable())
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getUserIdentifier() ?? $this->getClient()->getIdentifier())
            ->withClaim('scopes', $this->getScopes())
            ->withClaim('client_id', $this->getClient()->getIdentifier())
            ->getToken($this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey());
    }

    private function initJwtConfiguration(): void
    {
        $privateKeyContents = $this->privateKey->getKeyContents();

        if ('' === $privateKeyContents) {
            throw new RuntimeException('Private key is empty');
        }

        $this->jwtConfiguration = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($privateKeyContents, $this->privateKey->getPassPhrase() ?? ''),
            InMemory::plainText('empty', 'empty')
        );
    }
}
