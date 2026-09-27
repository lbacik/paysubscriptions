<?php

declare(strict_types=1);

namespace App\Controller;

use App\OAuth2\JwksProvider;
use App\OAuth2\OAuth2Config;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OAuth2 discovery for the approved CLI (issue #90, decision #82).
 *
 * Authorization Server Metadata (RFC 8414), Protected Resource Metadata
 * (RFC 9728), and the public JWKS (RFC 7517) live at standards-based paths
 * outside the `/api` resource prefix, so anonymous clients can discover the
 * issuer, endpoints, audience, and verification keys before starting sign-in.
 * Every advertised capability is implemented: the `api:full` scope,
 * authorization_code + refresh_token grants, S256 PKCE, and public clients
 * authenticating with `none` (confidential clients with `client_secret_post`).
 * Dynamic client registration (RFC 7591), revocation (RFC 7009), device
 * authorization (RFC 8628), userinfo, and introspection have no endpoint in
 * v1 and are therefore absent from the documents.
 */
final class OAuthDiscoveryController extends AbstractController
{
    public function __construct(
        #[Autowire('%app.oauth2.issuer%')]
        private readonly string $issuer,
        private readonly JwksProvider $jwks,
    ) {
    }

    #[Route('/.well-known/oauth-authorization-server', name: 'oauth_discovery_authorization_server', methods: ['GET'])]
    public function authorizationServer(): JsonResponse
    {
        $base = $this->base();

        return $this->cachedJson([
            'issuer' => $this->issuer,
            'authorization_endpoint' => $base.'/authorize',
            'token_endpoint' => $base.'/token',
            'jwks_uri' => $base.'/.well-known/jwks.json',
            'scopes_supported' => OAuth2Config::AVAILABLE_SCOPES,
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            // Only grants enabled on the token endpoint (client credentials,
            // password, implicit, and device grants are disabled).
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            // Public CLI clients use PKCE with no secret; confidential clients
            // post their secret (both verified in OAuthAuthorizeTest).
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
            // S256 is required for every client (OAuthAuthorizationListener).
            'code_challenge_methods_supported' => ['S256'],
        ]);
    }

    #[Route('/.well-known/oauth-protected-resource', name: 'oauth_discovery_protected_resource', methods: ['GET'])]
    #[Route('/.well-known/oauth-protected-resource/{resourcePath}', name: 'oauth_discovery_protected_resource_path', requirements: ['resourcePath' => '.+'], methods: ['GET'])]
    public function protectedResource(): JsonResponse
    {
        // The resource identifier is the API audience carried as the `aud`
        // claim of every access token, so the CLI can validate that a token
        // was minted for this API.
        return $this->cachedJson([
            'resource' => OAuth2Config::API_AUDIENCE,
            'authorization_servers' => [$this->issuer],
            'jwks_uri' => $this->base().'/.well-known/jwks.json',
            'scopes_supported' => OAuth2Config::AVAILABLE_SCOPES,
            'bearer_methods_supported' => ['header'],
            'resource_signing_alg_values_supported' => ['RS256'],
        ]);
    }

    #[Route('/.well-known/jwks.json', name: 'oauth_discovery_jwks', methods: ['GET'])]
    public function jwks(): JsonResponse
    {
        return $this->cachedJson($this->jwks->getJwks());
    }

    private function base(): string
    {
        return rtrim($this->issuer, '/');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function cachedJson(array $payload): JsonResponse
    {
        $response = new JsonResponse($payload, Response::HTTP_OK);
        $response->headers->set('Cache-Control', 'public, max-age=3600');

        return $response;
    }
}
