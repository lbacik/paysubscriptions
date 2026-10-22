<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders every API error API Platform does not render as the generic
 * `/errors/{status}` problem (decision #87, ADR 0007): the firewall's
 * plain-text 401s, 404/405 for unrouted `/api/*` paths, refusals on the
 * `/api` entrypoint, and throws from `ApiAccessListener` on the hand-written
 * routes (which Symfony's error controller would otherwise render as HTML).
 *
 * Runs early so later response listeners (site-wide security headers) still
 * apply to the converted response. Responses already in
 * `application/problem+json` (everything API Platform renders itself, and
 * the OAuth2 protocol endpoints which keep their own shape) pass through
 * untouched.
 *
 * The converter knows no domain types: the body is always the generic
 * problem with the status text as detail, in the same member order API
 * Platform serializes it (`title`, `detail`, `status`, `type`). The original
 * headers survive, so `WWW-Authenticate` (firewall 401s) and `Allow` (405s)
 * are kept; a 401 without a challenge gains the generic
 * `Bearer error="invalid_token"` one, which every 401 on this boundary
 * carries. `Content-Length` is dropped with the replaced body.
 */
final class ApiErrorResponseListener
{
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: 10)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $response = $event->getResponse();
        $status = $response->getStatusCode();

        if ($status < Response::HTTP_BAD_REQUEST) {
            return;
        }

        $contentType = (string) $response->headers->get('Content-Type');
        if (str_contains($contentType, 'application/problem+json')) {
            return;
        }

        $detail = Response::$statusTexts[$status] ?? 'An error occurred';

        $headers = $response->headers->all();
        unset($headers['content-length']);
        $headers['content-type'] = 'application/problem+json';
        if (Response::HTTP_UNAUTHORIZED === $status && !isset($headers['www-authenticate'])) {
            $headers['www-authenticate'] = 'Bearer error="invalid_token"';
        }

        $event->setResponse(new JsonResponse(
            [
                'title' => 'An error occurred',
                'detail' => $detail,
                'status' => $status,
                'type' => "/errors/{$status}",
            ],
            $status,
            $headers,
        ));
    }
}
