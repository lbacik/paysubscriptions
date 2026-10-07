<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\Problem;
use App\Connection\ClientApprovalPolicy;
use App\Connection\ConnectionDecision;
use App\Entity\User;
use App\OAuth2\OAuth2Config;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
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
 * Failures are returned directly as `application/problem+json` without
 * disclosing data: unknown or inactive client to 401, missing scope to 403,
 * and ineligible account to 403 (`account_inactive`).
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
            // 401, converted to problem+json by ApiProblemResponseListener.
            return;
        }

        // Every /api caller presents a User-delegated token: client
        // credentials alone never open User data (decision #82). This also
        // covers the /api entrypoint probe, which has no v1 scope or client
        // bookkeeping of its own.
        $user = $token->getUser();
        if (!$user instanceof User) {
            $event->setResponse($this->problem(
                Problem::INVALID_TOKEN,
                'Authentication required',
                Response::HTTP_UNAUTHORIZED,
                'Authentication is required to access this resource.',
            ));

            return;
        }

        if (!str_starts_with($path, '/api/v1')) {
            return;
        }

        // Explicit full-access scope only. A valid token without it is
        // authenticated but forbidden.
        if (!\in_array(OAuth2Config::SCOPE_FULL, $token->getScopes(), true)) {
            $event->setResponse($this->problem(
                Problem::INSUFFICIENT_SCOPE,
                'Forbidden',
                Response::HTTP_FORBIDDEN,
                'This resource requires the "api:full" scope.',
            ));

            return;
        }

        // The token's client must still be allowed to act for this User: a
        // registered, active client approved for api:full, and a verified
        // account. (The firewall user provider already rejects deleted Users
        // with a 401 before this point.) A deleted, deactivated, or narrowed
        // client invalidates its tokens. The active check matters because
        // access tokens are stateless: nothing else consults the client record
        // between issuance and expiry (up to 15 minutes). Each refusal keeps
        // this checkpoint's problem+json code: unknown or inactive client to
        // 401, missing scope or ineligible account to 403.
        $decision = $this->approvals->decide($user, $this->clients->find($token->getOAuthClientId()));

        if (ConnectionDecision::Allowed === $decision) {
            return;
        }

        [$code, $title, $status, $detail] = match ($decision) {
            ConnectionDecision::ClientUnknownOrInactive => [
                Problem::INVALID_TOKEN,
                'Authentication required',
                Response::HTTP_UNAUTHORIZED,
                'Authentication is required to access this resource.',
            ],
            ConnectionDecision::ClientScopeNotApproved => [
                Problem::INSUFFICIENT_SCOPE,
                'Forbidden',
                Response::HTTP_FORBIDDEN,
                'This resource requires the "api:full" scope.',
            ],
            ConnectionDecision::UserNotEligible => [
                Problem::ACCOUNT_INACTIVE,
                'Forbidden',
                Response::HTTP_FORBIDDEN,
                'This account is not eligible to use the API.',
            ],
        };

        $event->setResponse($this->problem($code, $title, $status, $detail));
    }

    private function problem(string $code, string $title, int $status, string $detail): JsonResponse
    {
        return new JsonResponse(
            Problem::body($code, $title, $status, $detail),
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
