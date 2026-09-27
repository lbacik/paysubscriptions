<?php

declare(strict_types=1);

namespace App\OAuth2;

use Defuse\Crypto\Key;
use League\OAuth2\Server\CryptTrait;
use Throwable;

/**
 * Decrypts opaque client-facing tokens (refresh tokens, authorization codes)
 * into their payload arrays (issue #92).
 *
 * League encrypts these values with the authorization-server encryption key
 * before handing them to clients, while repositories only ever see the
 * decrypted identifiers. The key is wired exactly like the bundle's own
 * `EncryptionKeyPass`: a `plain` password or a `defuse` ASCII-safe key.
 */
final class OpaqueTokenDecryptor
{
    use CryptTrait;

    public function __construct(string $encryptionKey, string $encryptionKeyType = 'plain')
    {
        $this->setEncryptionKey(
            'defuse' === $encryptionKeyType
                ? Key::loadFromAsciiSafeString($encryptionKey)
                : $encryptionKey
        );
    }

    /**
     * @return array<string, mixed>|null the payload, or null when the value
     *                                   is not a token this server encrypted
     */
    public function decryptToArray(string $opaqueToken): ?array
    {
        try {
            $decrypted = $this->decrypt($opaqueToken);
        } catch (Throwable) {
            return null;
        }

        try {
            $payload = json_decode($decrypted, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return \is_array($payload) ? $payload : null;
    }
}
