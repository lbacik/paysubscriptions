<?php

declare(strict_types=1);

namespace App\OAuth2;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Rejects refresh-token requests that target another resource (issue #91).
 *
 * Refresh tokens are bound to the PaySubscriptions API resource. A request
 * carrying a `resource` indicator (RFC 8707) for anything else fails with the
 * protocol-defined `invalid_target` error instead of being silently honored.
 * Requests without the indicator keep the family-bound resource. Indicators
 * are read from the form body, the query string, and JSON bodies; repeated
 * indicators are rejected outright.
 */
final class RefreshResourceListener
{
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 10)]
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || 'oauth2_token' !== $request->attributes->get('_route')) {
            return;
        }

        if ('refresh_token' !== $request->request->get('grant_type') && 'refresh_token' !== $request->query->get('grant_type')) {
            return;
        }

        $resources = $this->resourceIndicators($request);
        if ([] === $resources) {
            return;
        }

        if (1 !== \count($resources) || OAuth2Config::API_AUDIENCE !== $resources[0]) {
            $event->setResponse(new JsonResponse([
                'error' => 'invalid_target',
                'error_description' => 'The requested resource is not the PaySubscriptions API.',
            ], 400));
        }
    }

    /**
     * @return list<mixed>
     */
    private function resourceIndicators(Request $request): array
    {
        $indicators = [];

        foreach ([$request->request->all(), $request->query->all()] as $parameters) {
            if (!\array_key_exists('resource', $parameters)) {
                continue;
            }
            // A repeated indicator arrives as an array; a single one as a
            // scalar. Either way every value must be the API resource.
            foreach ((array) $parameters['resource'] as $value) {
                $indicators[] = $value;
            }
        }

        if ([] === $indicators && str_contains((string) $request->headers->get('CONTENT_TYPE'), 'json')) {
            $body = json_decode((string) $request->getContent(), true);
            if (\is_array($body) && \array_key_exists('resource', $body)) {
                foreach ((array) $body['resource'] as $value) {
                    $indicators[] = $value;
                }
            }
        }

        return $indicators;
    }
}
