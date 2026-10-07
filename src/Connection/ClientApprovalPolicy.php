<?php

declare(strict_types=1);

namespace App\Connection;

use App\Entity\User;
use App\OAuth2\OAuth2Config;

/**
 * The single Client-approval policy (issue #194, CONTEXT.md **Connection**,
 * ADR 0005): a Client may act for a User only if the User exists and is
 * verified, and the Client exists, is active, and is approved for `api:full`.
 *
 * This is the only implementation of that rule: the authorize, refresh, and
 * `/api/v1` checkpoints delegate to it instead of keeping their own copies.
 * It is deliberately free of any League (`league/oauth2-server` or the
 * bundle) dependency — the Client arrives as a plain object exposing the two
 * members the rule reads, so callers pass their League client straight
 * through without the policy importing it.
 */
final class ClientApprovalPolicy
{
    /**
     * @param object{isActive(): bool, getScopes(): iterable<mixed>}|null $client
     */
    public function decide(?User $user, ?object $client): ConnectionDecision
    {
        if (null === $user || !$user->isVerified()) {
            return ConnectionDecision::UserNotEligible;
        }

        if (null === $client || !$client->isActive()) {
            return ConnectionDecision::ClientUnknownOrInactive;
        }

        foreach ($client->getScopes() as $scope) {
            if (OAuth2Config::SCOPE_FULL === (string) $scope) {
                return ConnectionDecision::Allowed;
            }
        }

        return ConnectionDecision::ClientScopeNotApproved;
    }
}
