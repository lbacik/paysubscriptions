<?php

declare(strict_types=1);

namespace App\OAuth2;

use League\Bundle\OAuth2ServerBundle\Event\ScopeResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Enforces the single-scope policy (issue #86): only an explicit approved
 * api:full request may proceed. Empty, unknown, and unapproved scope requests
 * fail with invalid_scope instead of inheriting the bundle's permissive
 * no-scope behavior (a client with no scope would otherwise receive the
 * client's full scope set).
 */
final class StrictScopeListener
{
    #[AsEventListener(event: OAuth2Events::SCOPE_RESOLVE)]
    public function onScopeResolve(ScopeResolveEvent $event): void
    {
        $scopes = array_map(strval(...), $event->getScopes());
        sort($scopes);

        if ([OAuth2Config::SCOPE_FULL] !== $scopes) {
            throw OAuthServerException::invalidScope('' === implode('', $scopes) ? '(empty)' : implode(' ', $scopes));
        }
    }
}
