<?php

declare(strict_types=1);

namespace App\Tests\OAuth2;

use App\OAuth2\OAuth2Config;
use App\OAuth2\StrictScopeListener;
use League\Bundle\OAuth2ServerBundle\Event\ScopeResolveEvent;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use League\OAuth2\Server\Exception\OAuthServerException;
use PHPUnit\Framework\TestCase;

/**
 * API v1 has a single coarse scope (issue #86): only an explicit approved
 * api:full request may issue a User-delegated token. Empty, unknown, and
 * unapproved scope requests must fail instead of inheriting the bundle's
 * permissive no-scope behavior.
 */
final class StrictScopeListenerTest extends TestCase
{
    private StrictScopeListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->listener = new StrictScopeListener();
    }

    public function testAcceptsExplicitApiFullScope(): void
    {
        $event = $this->resolveEvent([OAuth2Config::SCOPE_FULL]);

        $this->listener->onScopeResolve($event);

        self::assertSame([OAuth2Config::SCOPE_FULL], array_map(strval(...), $event->getScopes()));
    }

    public function testRejectsEmptyScopeRequest(): void
    {
        $this->expectException(OAuthServerException::class);

        $this->listener->onScopeResolve($this->resolveEvent([]));
    }

    public function testRejectsUnknownScope(): void
    {
        $this->expectException(OAuthServerException::class);

        $this->listener->onScopeResolve($this->resolveEvent(['api:read']));
    }

    public function testRejectsScopeOutsideClientApproval(): void
    {
        $this->expectException(OAuthServerException::class);

        // Even if a finalized set somehow contains another scope, it fails.
        $this->listener->onScopeResolve($this->resolveEvent([OAuth2Config::SCOPE_FULL, 'email']));
    }

    /**
     * @param list<string> $scopes
     */
    private function resolveEvent(array $scopes): ScopeResolveEvent
    {
        $client = new Client('Test CLI', 'test-cli', null);

        return new ScopeResolveEvent(
            array_map(static fn (string $scope): Scope => new Scope($scope), $scopes),
            new Grant('authorization_code'),
            $client,
            'user@example.com',
        );
    }
}
