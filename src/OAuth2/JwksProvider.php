<?php

declare(strict_types=1);

namespace App\OAuth2;

use RuntimeException;

/**
 * Publishes the authorization server's public verification key as a JWK Set
 * (RFC 7517, issue #90).
 *
 * Only public verification material is ever exposed: the RSA modulus (`n`)
 * and exponent (`e`) plus the `RS256`/`sig` usage markers. Private key
 * material is never read — the provider parses the configured *public* key —
 * so even a compromised response cannot leak signing capability.
 */
final class JwksProvider
{
    /** @var array{keys: list<array{kty: string, use: string, alg: string, n: string, e: string}>}|null */
    private ?array $cached = null;

    public function __construct(
        private readonly string $publicKeyPath,
    ) {
    }

    /**
     * @return array{keys: list<array{kty: string, use: string, alg: string, n: string, e: string}>}
     */
    public function getJwks(): array
    {
        if (null !== $this->cached) {
            return $this->cached;
        }

        $details = openssl_pkey_get_details($this->readPublicKey());
        if (false === $details || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new RuntimeException('The OAuth2 public key is not a readable RSA key.');
        }

        return $this->cached = [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'n' => $this->base64UrlEncode($details['rsa']['n']),
                    'e' => $this->base64UrlEncode($details['rsa']['e']),
                ],
            ],
        ];
    }

    /**
     * @return \OpenSSLAsymmetricKey|\OpenSSLCertificate
     */
    private function readPublicKey()
    {
        $source = $this->publicKeyPath;
        if (str_starts_with($source, 'file://')) {
            $source = substr($source, \strlen('file://'));
        }

        $contents = str_starts_with(trim($source), '-----BEGIN')
            ? $source
            : @file_get_contents($source);

        if (!\is_string($contents) || '' === $contents) {
            throw new RuntimeException('The OAuth2 public key cannot be read.');
        }

        $key = @openssl_pkey_get_public($contents);
        if (false === $key) {
            throw new RuntimeException('The OAuth2 public key cannot be parsed.');
        }

        return $key;
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
