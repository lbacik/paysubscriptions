<?php

declare(strict_types=1);

namespace App\Tests\Api\V1;

use League\OAuth2\Server\Entities\ScopeEntityInterface;

/**
 * A scope claim for tokens minted by MintsAccessTokens.
 */
final class AccessTokenScope implements ScopeEntityInterface
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
