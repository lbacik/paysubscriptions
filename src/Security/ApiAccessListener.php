<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\ProblemException;
use App\Connection\ClientApprovalPolicy;
use App\Connection\ConnectionDecision;
use App\Entity\User;
use App\OAuth2\OAuth2Config;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces the API v1 bearer contract beyond signature/issuer/audience/expiry
 * (issue #89): explicit `api:full` scope on the token itself, plus the shared
 * ClientApprovalPolicy's answer to "may this Client act for this User?" (a
 * registered, active client approved for that scope, and an existing eligible
 * (verified) User). Stateless and limited to `^/api` so session-based web
 * login is untouched; scope and client checks apply to `^/api/v1` while the
 * User-delegation check also guards the `/api` entrypoint probe.
 *
 * Failures throw: 401s are `UnauthorizedHttpException` (rendered as the
 * generic `/errors/401` problem), 403s are `ProblemException`s
 * (`/problems/insufficient-scope` with the `WWW-Authenticate` scope
 * challenge, or `/problems/account-inactive`). API Platform renders the
 * throws on the resource routes; the generic edge converter renders them
 * on the non-resource paths (the `/api` entrypoint) and for firewall
 * refusals.
 */
final class ApiAccessListener
{
    public function __construct(
        private readonly Security $security,
        private readonly ClientManagerInterface $clients,
        private readonly ClientApprovalPolicy $approvals,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: -5)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api')) {
            return;
        }

        $token = $this->security->getToken();
        if (!$token instanceof OAuth2Token) {
            // No bearer authentication: the firewall entrypoint produces the
            // 401, converted to a problem by the edge converter.
            return;
        }

        // Every /api caller presents a User-delegated token: client
        // credentials alone never open User data (decision #82). This also
        // covers the /api entrypoint probe, which has no v1 scope or client
        // bookkeeping of its own.
        $user = $token->getUser();
        if (!$user instanceof User) {
            throw self::invalidToken();
        }

        if (!str_starts_with($path, '/api/v1')) {
            return;
        }

        // Explicit full-access scope only. A valid token without it is
        // authenticated but forbidden.
        if (!\in_array(OAuth2Config::SCOPE_FULL, $token->getScopes(), true)) {
            throw self::insufficientScope();
        }

        // The token's client must still be allowed to act for this User: a
        // registered, active client approved for api:full, and a verified
        // account. (The firewall user provider rejects deleted Users
        // with a 401 before this point.) A deleted, deactivated, or narrowed
        // client invalidates its tokens. The active check matters because
        // access tokens are stateless: nothing else consults the client record
        // between issuance and expiry (up to 15 minutes).
        $decision = $this->approvals->decide($user, $this->clients->find($token->getOAuthClientId()));

        if (ConnectionDecision::Allowed === $decision) {
            return;
        }

        if (ConnectionDecision::ClientUnknownOrInactive === $decision) {
            throw self::invalidToken();
        }

        if (ConnectionDecision::ClientScopeNotApproved === $decision) {
            throw self::insufficientScope();
        }

        throw new ProblemException(
            '/problems/account-inactive',
            'Forbidden',
            403,
            'This account is not eligible to use the API.',
        );
    }

    private static function invalidToken(): UnauthorizedHttpException
    {
        return new UnauthorizedHttpException(
            'Bearer error="invalid_token"',
            'Authentication is required to access this resource.',
        );
    }

    private static function insufficientScope(): ProblemException
    {
        return new ProblemException(
            '/problems/insufficient-scope',
            'Forbidden',
            403,
            'This resource requires the "api:full" scope.',
            null,
            ['WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="api:full"'],
        );
    }
}
