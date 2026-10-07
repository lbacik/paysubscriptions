<?php

declare(strict_types=1);

namespace App\Tests\Connection;

use App\Connection\ClientApprovalPolicy;
use App\Connection\ConnectionDecision;
use App\Entity\User;
use App\OAuth2\OAuth2Config;
use PHPUnit\Framework\TestCase;

/**
 * Single Client-approval policy (issue #194): one rule answers "may this
 * Client act for this User?" for the authorize, refresh, and /api/v1
 * checkpoints. A Client may act for a User only if the User exists and is
 * verified, and the Client exists, is active, and is approved for api:full.
 *
 * Seam: the pure domain decision, observed through the returned enum only.
 * No League, no database, no exceptions for normal refusals.
 */
final class ClientApprovalPolicyTest extends TestCase
{
    private ClientApprovalPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new ClientApprovalPolicy();
    }

    public function testVerifiedUserWithActiveApprovedClientIsAllowed(): void
    {
        self::assertSame(
            ConnectionDecision::Allowed,
            $this->policy->decide($this->user(verified: true), $this->client(active: true, scopes: [OAuth2Config::SCOPE_FULL])),
        );
    }

    public function testMissingUserIsRefusedAsUserNotEligible(): void
    {
        self::assertSame(
            ConnectionDecision::UserNotEligible,
            $this->policy->decide(null, $this->client(active: true, scopes: [OAuth2Config::SCOPE_FULL])),
        );
    }

    public function testUnverifiedUserIsRefusedAsUserNotEligible(): void
    {
        self::assertSame(
            ConnectionDecision::UserNotEligible,
            $this->policy->decide($this->user(verified: false), $this->client(active: true, scopes: [OAuth2Config::SCOPE_FULL])),
        );
    }

    public function testDeletedClientIsRefusedAsUnknownOrInactive(): void
    {
        self::assertSame(
            ConnectionDecision::ClientUnknownOrInactive,
            $this->policy->decide($this->user(verified: true), null),
        );
    }

    public function testInactiveClientIsRefusedAsUnknownOrInactive(): void
    {
        self::assertSame(
            ConnectionDecision::ClientUnknownOrInactive,
            $this->policy->decide($this->user(verified: true), $this->client(active: false, scopes: [OAuth2Config::SCOPE_FULL])),
        );
    }

    public function testActiveClientWithoutFullScopeIsRefusedAsScopeNotApproved(): void
    {
        self::assertSame(
            ConnectionDecision::ClientScopeNotApproved,
            $this->policy->decide($this->user(verified: true), $this->client(active: true, scopes: ['api:limited'])),
        );
    }

    public function testActiveClientWithoutAnyScopeIsRefusedAsScopeNotApproved(): void
    {
        self::assertSame(
            ConnectionDecision::ClientScopeNotApproved,
            $this->policy->decide($this->user(verified: true), $this->client(active: true, scopes: [])),
        );
    }

    public function testUserRefusalWinsWhenBothSidesAreBad(): void
    {
        // Documents the evaluation order: the User is checked before the
        // Client, so a bad User plus a bad Client reports UserNotEligible.
        self::assertSame(
            ConnectionDecision::UserNotEligible,
            $this->policy->decide($this->user(verified: false), $this->client(active: false, scopes: [])),
        );
    }

    private function user(bool $verified): User
    {
        return (new User())->setEmail('policy-user@example.com')->setVerified($verified);
    }

    /**
     * @param list<string> $scopes
     */
    private function client(bool $active, array $scopes): object
    {
        return new class($active, $scopes) {
            /**
             * @param list<string> $scopes
             */
            public function __construct(
                private readonly bool $active,
                private readonly array $scopes,
            ) {
            }

            public function isActive(): bool
            {
                return $this->active;
            }

            /**
             * @return list<PolicyTestScope>
             */
            public function getScopes(): array
            {
                return array_map(static fn (string $scope): PolicyTestScope => new PolicyTestScope($scope), $this->scopes);
            }
        };
    }
}

final class PolicyTestScope
{
    public function __construct(private readonly string $identifier)
    {
    }

    public function __toString(): string
    {
        return $this->identifier;
    }
}
