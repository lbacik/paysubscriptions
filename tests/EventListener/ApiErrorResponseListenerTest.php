<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\ApiErrorResponseListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The `^/api` edge converter's challengeless-401 fallback: a 401 with no
 * `WWW-Authenticate` at all gains the generic `Bearer error="invalid_token"`
 * challenge. (Functionally every 401 on the boundary carries a challenge —
 * the firewall sets bare `Bearer`, the gate throws `invalid_token` — so this
 * path is pinned here at unit level, byte-for-byte like API Platform renders.)
 */
final class ApiErrorResponseListenerTest extends TestCase
{
    public function testUnauthorizedWithoutChallengeGainsInvalidTokenChallenge(): void
    {
        $kernel = new class() implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                throw new \LogicException('must not be called');
            }
        };

        $event = new ResponseEvent(
            $kernel,
            Request::create('/api/v1/expense-categories'),
            HttpKernelInterface::MAIN_REQUEST,
            new Response('Unauthorized', Response::HTTP_UNAUTHORIZED, ['Content-Type' => 'text/plain']),
        );

        (new ApiErrorResponseListener())->onKernelResponse($event);

        $response = $event->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('Bearer error="invalid_token"', $response->headers->get('WWW-Authenticate'));
        self::assertSame(
            ['title' => 'An error occurred', 'detail' => 'Unauthorized', 'status' => 401, 'type' => '/errors/401'],
            json_decode((string) $response->getContent(), true),
        );
        self::assertStringContainsString('"type":"/errors/401"', (string) $response->getContent());
    }
}
