<?php

declare(strict_types=1);

namespace App\Tests\Api\V1;

use App\Entity\User;
use Symfony\Component\Uid\Uuid;

/**
 * The `^/api` edge converter: any error response API Platform did not render
 * itself becomes the generic `/errors/{status}` problem, keeping the
 * original headers. Covers the firewall's plain-text 401s, routing 404/405s
 * for unrouted paths, and listener throws on the hand-written routes.
 */
final class ApiErrorEdgeTest extends ApiV1TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('api-edge-user@example.com');
    }

    public function testFirewall401BecomesGenericProblemAndKeepsChallenge(): void
    {
        $this->client->request('GET', '/api/v1/expense-categories');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        self::assertJsonContains(['type' => '/errors/401', 'status' => 401]);
    }

    public function testUnroutedPathBecomesGenericProblem(): void
    {
        $this->requestAs($this->user, 'GET', '/api/v1/does-not-exist');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertJsonContains([
            'title' => 'An error occurred',
            'detail' => 'Not Found',
            'status' => 404,
            'type' => '/errors/404',
        ]);
    }

    public function testMethodNotAllowedBecomesGenericProblemAndKeepsAllow(): void
    {
        $response = $this->requestAs($this->user, 'POST', '/api/v1/openapi.json');

        self::assertResponseStatusCodeSame(405);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        $allow = implode(',', $response->getHeaders(false)['allow'] ?? []);
        self::assertStringContainsString('GET', $allow);
        self::assertJsonContains(['type' => '/errors/405', 'status' => 405]);
    }

    public function testListenerThrowOnHandWrittenRouteBecomesGenericProblemButKeepsChallenge(): void
    {
        // The hand-written contract document has no API Platform rendering:
        // ApiAccessListener throws, Symfony renders HTML, the edge converts
        // to the generic problem. The domain type cannot survive HTML, but
        // the scope challenge does.
        $this->client->request('GET', '/api/v1/openapi.json', ['auth_bearer' => $this->craftToken('api-edge-user@example.com', scope: 'api:read')]);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="api:full"');
        self::assertJsonContains(['type' => '/errors/403', 'status' => 403]);
    }

    public function testEdgeBodyMatchesPlatformBody(): void
    {
        // The same generic problem API Platform renders itself: an unknown
        // expense-category id renders the platform 404, an unrouted path the
        // edge 404. Both carry the same type/title/status/detail (the debug
        // trace only exists in the platform rendering and is excluded).
        $platform = $this->requestAs($this->user, 'GET', '/api/v1/expense-categories/'.Uuid::v4())->toArray(false);
        $edge = $this->requestAs($this->user, 'GET', '/api/v1/does-not-exist')->toArray(false);

        $pick = static fn(array $body): array => [
            'type' => $body['type'],
            'title' => $body['title'],
            'status' => $body['status'],
            'detail' => $body['detail'],
        ];

        self::assertSame($pick($platform), $pick($edge));
        self::assertSame('/errors/404', $pick($edge)['type']);
    }
}
