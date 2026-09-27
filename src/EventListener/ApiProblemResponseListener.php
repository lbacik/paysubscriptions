<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Api\Problem;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Guarantees the API v1 error contract (issue #89, decision #87).
 *
 * Resource errors use `application/problem+json` with stable type/code,
 * status, and safe detail. The firewall entrypoint and authenticator return
 * plain-text 401/403 bodies, and routing returns HTML 404 pages; for
 * `^/api/v1` those are converted here so missing/invalid tokens never leak
 * data and always present the documented shape. Responses already in
 * `application/problem+json` (e.g. category 404 from the controller) pass
 * through untouched.
 */
final class ApiProblemResponseListener
{
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/v1')) {
            return;
        }

        $response = $event->getResponse();
        $status = $response->getStatusCode();

        if (!\in_array($status, [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN, Response::HTTP_NOT_FOUND], true)) {
            return;
        }

        $contentType = (string) $response->headers->get('Content-Type');
        if (str_contains($contentType, 'application/problem+json')) {
            return;
        }

        $event->setResponse(new JsonResponse(
            match ($status) {
                Response::HTTP_UNAUTHORIZED => Problem::body(
                    Problem::UNAUTHORIZED,
                    'Authentication required',
                    Response::HTTP_UNAUTHORIZED,
                    'Authentication is required to access this resource.',
                ),
                Response::HTTP_FORBIDDEN => Problem::body(
                    Problem::FORBIDDEN,
                    'Forbidden',
                    Response::HTTP_FORBIDDEN,
                    'You do not have permission to access this resource.',
                ),
                default => Problem::body(
                    Problem::NOT_FOUND,
                    'Not found',
                    Response::HTTP_NOT_FOUND,
                    'No resource was found at this location.',
                ),
            },
            $status,
            ['Content-Type' => 'application/problem+json'],
        ));
    }
}
