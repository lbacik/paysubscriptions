<?php

declare(strict_types=1);

namespace App\EventListener;

use App\RateLimiter\RateLimitGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Abuse protection for the public OAuth2 token endpoint (issue #143).
 *
 * Authorization codes and refresh tokens are high-entropy, but the endpoint
 * itself still answers every caller — including credential-stuffing and
 * token-guessing scripts — with oracle-grade error distinctions at full
 * speed. Each POST to /token therefore consumes one hit from a per-client
 * window and one from a per-IP window; once either burst is exhausted the
 * request is rejected with 429 before it reaches the authorization server,
 * so no cryptographic work is spent on throttled callers.
 *
 * Only POST requests consume a budget: anything else is rejected by the
 * OAuth layer anyway, and counting it would let junk traffic burn the burst
 * of legitimate callers. Requests without a client_id skip the per-client
 * window (there is no client to attribute them to) and count only against
 * the per-IP window, so keyless scripts cannot lock each other out through
 * a shared anonymous bucket.
 *
 * The bursts resolve from the environment (RATE_LIMIT_TOKEN_CLIENT,
 * RATE_LIMIT_TOKEN_IP; see config/packages/rate_limiter.yaml): the test
 * suite pins them to opt in per test.
 */
final class OAuthTokenRateLimitListener
{
    public function __construct(
        #[Autowire(service: 'limiter.oauth_token_client')]
        private readonly RateLimiterFactory $clientLimiter,
        #[Autowire(service: 'limiter.oauth_token_ip')]
        private readonly RateLimiterFactory $ipLimiter,
        private readonly RateLimitGuard $rateLimitGuard,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ('/token' !== $request->getPathInfo() || !$request->isMethod('POST')) {
            return;
        }

        $clientId = (string) $request->request->get('client_id', '');

        $windows = [];

        if ('' !== $clientId) {
            $windows[] = [$this->clientLimiter, $clientId];
        }

        $windows[] = [$this->ipLimiter, (string) $request->getClientIp()];

        $retryAfter = $this->rateLimitGuard->retryAfterSeconds($windows);

        if (null === $retryAfter) {
            return;
        }

        $event->setResponse(new JsonResponse(
            [
                'error' => 'temporarily_unavailable',
                'error_description' => 'Too many token requests. Please try again later.',
            ],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $retryAfter],
        ));
    }
}
