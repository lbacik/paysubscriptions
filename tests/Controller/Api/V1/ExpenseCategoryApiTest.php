<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1;

use App\Service\ExpenseCategoryService;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read User-owned ExpenseCategories through protected API v1 (issue #89).
 *
 * Seam: public HTTP interface GET /api/v1/expense-categories (+ detail).
 * Behavior is observed through status, content-type, and body only.
 */
final class ExpenseCategoryApiTest extends ExpenseCategoryApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('api-cat-user@example.com', 'Fixture-Password-1', true);
        $this->other = $this->createUser('api-cat-other@example.com', 'Fixture-Password-1', true);
        $this->registerPublicClient();
    }

    public function testCollectionReturnsOwnedCategoriesOrderedByNameAsJson(): void
    {
        $categories = static::getContainer()->get(ExpenseCategoryService::class);
        $categories->create($this->user, 'Zebra', '#111111');
        $categories->create($this->user, 'Apple', '#222222');
        $categories->create($this->other, 'Foreign', '#333333');

        $this->client->request('GET', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-cat-user@example.com'),
            'HTTP_Accept' => 'application/json',
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertCount(2, $data);
        self::assertSame('Apple', $data[0]['name']);
        self::assertSame('Zebra', $data[1]['name']);

        foreach ($data as $row) {
            self::assertArrayHasKey('id', $row);
            self::assertArrayHasKey('name', $row);
            self::assertArrayHasKey('color', $row);
            self::assertArrayHasKey('createdAt', $row);
            self::assertArrayHasKey('updatedAt', $row);
            self::assertArrayNotHasKey('owner', $row);
        }

        // Never leaks the other User's category.
        $names = array_column($data, 'name');
        self::assertNotContains('Foreign', $names);
    }

    public function testDetailReturnsOwnedCategoryAsJson(): void
    {
        $categories = static::getContainer()->get(ExpenseCategoryService::class);
        $category = $categories->create($this->user, 'Food', '#ff0000');

        $this->client->request('GET', '/api/v1/expense-categories/'.$category->getId(), [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-cat-user@example.com'),
            'HTTP_Accept' => 'application/json',
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame((string) $category->getId(), $data['id']);
        self::assertSame('Food', $data['name']);
        self::assertSame('#ff0000', $data['color']);
        self::assertArrayHasKey('createdAt', $data);
        self::assertArrayHasKey('updatedAt', $data);
        self::assertArrayNotHasKey('owner', $data);
    }

    public function testDetailWithMissingIdReturnsProblemNotFound(): void
    {
        $missing = (string) \Symfony\Component\Uid\Uuid::v4();

        $this->client->request('GET', '/api/v1/expense-categories/'.$missing, [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-cat-user@example.com'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(404, $problem['status']);
        self::assertSame('category_not_found', $problem['code']);
        self::assertArrayHasKey('type', $problem);
        self::assertArrayHasKey('title', $problem);
        self::assertArrayHasKey('detail', $problem);
    }

    public function testDetailWithForeignIdReturnsProblemNotFoundWithoutDisclosure(): void
    {
        $categories = static::getContainer()->get(ExpenseCategoryService::class);
        $foreign = $categories->create($this->other, 'Foreign', '#333333');

        $this->client->request('GET', '/api/v1/expense-categories/'.$foreign->getId(), [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-cat-user@example.com'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('category_not_found', $problem['code']);
        self::assertStringNotContainsStringIgnoringCase('Foreign', (string) $this->client->getResponse()->getContent());
    }

    public function testDetailWithInvalidIdReturnsProblemNotFound(): void
    {
        $this->client->request('GET', '/api/v1/expense-categories/not-a-uuid', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-cat-user@example.com'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testCollectionWithoutTokenReturnsProblemUnauthorized(): void
    {
        $this->client->request('GET', '/api/v1/expense-categories');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(401, $problem['status']);
        self::assertArrayHasKey('code', $problem);
        self::assertArrayHasKey('type', $problem);
    }

    public function testCollectionWithTamperedTokenReturnsProblemUnauthorized(): void
    {
        $token = $this->craftToken('api-cat-user@example.com');
        $tampered = substr($token, 0, -1).('A' === substr($token, -1) ? 'B' : 'A');

        $this->client->request('GET', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$tampered,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testCollectionWithExpiredTokenReturnsProblemUnauthorized(): void
    {
        $this->client->request('GET', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-cat-user@example.com', expiry: new DateTimeImmutable('-1 hour')),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testCollectionWithWrongAudienceReturnsProblemUnauthorized(): void
    {
        $this->client->request('GET', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-cat-user@example.com', audience: 'urn:example:other'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testCollectionWithWrongIssuerReturnsProblemUnauthorized(): void
    {
        $this->client->request('GET', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-cat-user@example.com', issuer: 'https://evil.example'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testCollectionWithWrongScopeReturnsProblemForbidden(): void
    {
        $this->client->request('GET', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-cat-user@example.com', scope: 'api:read'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(403, $problem['status']);
        self::assertSame('insufficient_scope', $problem['code']);
    }

    public function testCollectionWithUnknownClientReturnsProblemUnauthorized(): void
    {
        $this->client->request('GET', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-cat-user@example.com', clientId: 'unknown-cli'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testCollectionWithUnverifiedUserReturnsProblemForbidden(): void
    {
        $unverified = $this->createUser('api-unverified@example.com', 'Fixture-Password-1', false);

        $this->client->request('GET', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-unverified@example.com'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('account_inactive', $problem['code']);
    }

    public function testCollectionWithDeletedUserReturnsProblemUnauthorized(): void
    {
        $token = $this->craftToken('api-cat-user@example.com');

        // Delete the User after the token was minted.
        $this->em->remove($this->user);
        $this->em->flush();
        $this->em->clear();

        $this->client->request('GET', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testWebLoginStillWorksAfterApiChange(): void
    {
        // Session-based web login is preserved: the same User can sign in and
        // open the HTML category list while the stateless API stays separate.
        $this->login('api-cat-user@example.com', 'Fixture-Password-1');
        $this->client->request('GET', '/category');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/html', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testIssuedOAuthTokenOpensExpenseCategoryCollection(): void
    {
        // End-to-end through the real authorize/consent/token dance: the
        // User-delegated token opens the v1 collection.
        $this->client->loginUser($this->user);

        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $url = '/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => 'http://127.0.0.1:54123/callback',
            'scope' => \App\OAuth2\OAuth2Config::SCOPE_FULL,
            'state' => 'api-cat-state',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $crawler = $this->client->request('GET', $url);
        $csrf = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', $url, ['decision' => 'allow', '_csrf_token' => $csrf]);
        parse_str(
            (string) parse_url((string) $this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY),
            $query
        );

        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => 'http://127.0.0.1:54123/callback',
            'code' => $query['code'],
            'code_verifier' => $verifier,
        ]);
        self::assertResponseIsSuccessful();
        $token = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->client->request('GET', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token['access_token'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function testOpenApiDocumentCoversRoutesSchemasSecurityAndErrors(): void
    {
        $this->client->request('GET', '/api/v1/openapi.json', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-cat-user@example.com'),
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $doc = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('3.0.3', $doc['openapi']);

        // Routes.
        self::assertArrayHasKey('/expense-categories', $doc['paths']);
        self::assertArrayHasKey('/expense-categories/{id}', $doc['paths']);
        self::assertArrayHasKey('/openapi.json', $doc['paths']);

        // Schemas.
        $category = $doc['components']['schemas']['ExpenseCategory'];
        foreach (['id', 'name', 'color', 'createdAt', 'updatedAt'] as $field) {
            self::assertContains($field, $category['required']);
            self::assertArrayHasKey($field, $category['properties']);
        }
        self::assertArrayNotHasKey('owner', $category['properties']);

        $problem = $doc['components']['schemas']['Problem'];
        foreach (['type', 'title', 'status', 'detail', 'code'] as $field) {
            self::assertContains($field, $problem['required']);
        }

        // Security: OAuth2 authorization code with PKCE, single api:full scope.
        $scheme = $doc['components']['securitySchemes']['paySubsOAuth2'];
        self::assertSame('oauth2', $scheme['type']);
        self::assertArrayHasKey('api:full', $scheme['flows']['authorizationCode']['scopes']);
        self::assertSame('/authorize', $scheme['flows']['authorizationCode']['authorizationUrl']);
        self::assertSame('/token', $scheme['flows']['authorizationCode']['tokenUrl']);

        // Statuses and problem errors.
        $listResponses = $doc['paths']['/expense-categories']['get']['responses'];
        self::assertArrayHasKey('200', $listResponses);
        self::assertArrayHasKey('401', $listResponses);
        self::assertArrayHasKey('403', $listResponses);

        $detailResponses = $doc['paths']['/expense-categories/{id}']['get']['responses'];
        foreach (['200', '401', '403', '404'] as $status) {
            self::assertArrayHasKey($status, $detailResponses);
        }

        // Every error response uses application/problem+json.
        foreach (['Unauthorized', 'Forbidden', 'CategoryNotFound'] as $name) {
            $response = $doc['components']['responses'][$name];
            self::assertArrayHasKey('application/problem+json', $response['content']);
        }
    }

    public function testOpenApiRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/openapi.json');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        // The production deploy smoke greps for this exact code, so lock the
        // shape here (issue #101): a bare 401 without the problem document
        // would pass the status assertion but fail the release.
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('unauthorized', $body['code'] ?? null);
    }
}
