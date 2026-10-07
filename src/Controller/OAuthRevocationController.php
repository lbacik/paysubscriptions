<?php

declare(strict_types=1);

namespace App\Controller;

use App\Connection\ConnectionLifecycle;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RFC 7009 token revocation for OAuth2 clients (ADR 0005).
 *
 * A client presents its refresh token with its own identity (plus its secret
 * when confidential) and the one usable Client session behind that token is
 * revoked: the presented token and every other usable token of its family
 * stop refreshing. The Connection stays — the remembered consent is kept, so
 * the next authorization auto-approves instead of showing the consent screen
 * again.
 *
 * Self-contained access tokens cannot be revoked: presenting one succeeds
 * without claiming a revocation, and the token stays usable until its
 * 15-minute expiry. Unknown tokens and tokens owned by another client also
 * succeed without effect, so the endpoint never discloses token ownership.
 * Errors keep protocol-defined `error` codes, not `application/problem+json`.
 */
final class OAuthRevocationController extends AbstractController
{
    public function __construct(
        private readonly ClientManagerInterface $clients,
        private readonly ConnectionLifecycle $connections,
        #[Autowire(service: 'league.oauth2_server.password_hasher')]
        private readonly PasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('/revoke', name: 'oauth_revoke', methods: ['POST'])]
    public function revoke(Request $request): Response
    {
        $token = $request->request->get('token');
        if (!\is_string($token) || '' === $token) {
            return $this->protocolError(
                'invalid_request',
                'A `token` parameter is required.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        $clientId = $request->request->get('client_id');
        if (!\is_string($clientId) || '' === $clientId) {
            return $this->protocolError(
                'invalid_client',
                'Client authentication failed.',
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $client = $this->clients->find($clientId);
        if (null === $client || !$client->isActive()) {
            return $this->protocolError(
                'invalid_client',
                'Client authentication failed.',
                Response::HTTP_UNAUTHORIZED,
            );
        }

        if ($client->isConfidential() && !$this->secretValid($client->getSecret(), $request->request->get('client_secret'))) {
            return $this->protocolError(
                'invalid_client',
                'Client authentication failed.',
                Response::HTTP_UNAUTHORIZED,
            );
        }

        // The hint is advisory only: a refresh token revokes its one usable
        // Client session whatever the hint says, while an access token (or an
        // unknown token) simply has no revocable session and succeeds. The
        // Connection is deliberately kept, so the client re-authorizes without
        // the User consenting again.
        $this->connections->revokeSessionByOpaqueToken($token, $clientId);

        return $this->noCache(new Response('', Response::HTTP_OK));
    }

    private function secretValid(?string $storedSecret, mixed $inputSecret): bool
    {
        if (!\is_string($inputSecret) || '' === $inputSecret) {
            return false;
        }

        return $this->passwordHasher->verify((string) $storedSecret, $inputSecret);
    }

    private function protocolError(string $error, string $description, int $status): Response
    {
        $response = new JsonResponse(
            ['error' => $error, 'error_description' => $description],
            $status,
        );

        if (Response::HTTP_UNAUTHORIZED === $status) {
            $response->headers->set('WWW-Authenticate', 'Basic realm="OAuth"');
        }

        return $this->noCache($response);
    }

    private function noCache(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
