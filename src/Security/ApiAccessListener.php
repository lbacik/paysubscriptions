<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\Problem;
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
 * (issue #89): explicit `api:full` scope, a registered client approved for
 * that scope, and an existing eligible (verified) User. Stateless and limited
 * to `^/api` so session-based web login is untouched; scope and client checks
 * apply to `^/api/v1` while the User-delegation check also guards the `/api`
 * entrypoint probe.
 *
 * Failures are returned directly as `application/problem+json` without
 * disclosing data: unknown client or User collapse to 401, missing scope or
 * ineligible account to 403.
 */
final class ApiAccessListener
{
    public function __construct(
        private readonly Security $security,
        private readonly ClientManagerInterface $clients,
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

        // The token's client must still be registered and approved for
        // api:full. A deleted or narrowed client invalidates its tokens.
        $client = $this->clients->find($token->getOAuthClientId());
        if (null === $client) {
            $event->setResponse($this->problem(
                Problem::INVALID_TOKEN,
                'Authentication required',
                Response::HTTP_UNAUTHORIZED,
                'Authentication is required to access this resource.',
            ));

            return;
        }

        $allowsFull = false;
        foreach ($client->getScopes() as $scope) {
            if (OAuth2Config::SCOPE_FULL === (string) $scope) {
                $allowsFull = true;
                break;
            }
        }
        if (!$allowsFull) {
            $event->setResponse($this->problem(
                Problem::INSUFFICIENT_SCOPE,
                'Forbidden',
                Response::HTTP_FORBIDDEN,
                'This resource requires the "api:full" scope.',
            ));

            return;
        }

        // The firewall user provider already rejects deleted Users with a
        // 401; here we additionally require a verified account.
        if (!$user->isVerified()) {
            $event->setResponse($this->problem(
                Problem::ACCOUNT_INACTIVE,
                'Forbidden',
                Response::HTTP_FORBIDDEN,
                'This account is not eligible to use the API.',
            ));
        }
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
