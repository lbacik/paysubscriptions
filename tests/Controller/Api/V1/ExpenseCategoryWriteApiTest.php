<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1;

use App\Service\ExpenseCategoryService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manage User-owned ExpenseCategories through protected API v1 (write slice).
 *
 * Seam: public HTTP interface POST /api/v1/expense-categories,
 * PATCH/DELETE /api/v1/expense-categories/{id}. Behavior is observed through
 * status, content-type, and body only; the web UI keeps enforcing the same
 * rules through ExpenseCategoryService.
 */
final class ExpenseCategoryWriteApiTest extends ExpenseCategoryApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('api-write-user@example.com', 'Fixture-Password-1', true);
        $this->other = $this->createUser('api-write-other@example.com', 'Fixture-Password-1', true);
        $this->registerPublicClient();
    }

    public function testCreateReturns201WithServerGeneratedIdAndTimestamps(): void
    {
        $this->requestJson('POST', '/api/v1/expense-categories', [
            'name' => 'Food',
            'color' => '#ff0000',
        ], 'api-write-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Food', $data['name']);
        self::assertSame('#ff0000', $data['color']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $data['id'],
        );
        self::assertArrayHasKey('createdAt', $data);
        self::assertArrayHasKey('updatedAt', $data);
        self::assertArrayNotHasKey('owner', $data);

        // The record really belongs to the token User.
        $stored = $this->freshEm()->getRepository(\App\Entity\ExpenseCategory::class)->find(
            \Symfony\Component\Uid\Uuid::fromString($data['id']),
        );
        self::assertNotNull($stored);
        self::assertTrue($stored->isOwnedBy($this->freshUser('api-write-user@example.com')));
    }

    public function testCreateIsScopedToTokenUser(): void
    {
        $this->requestJson('POST', '/api/v1/expense-categories', [
            'name' => 'Mine',
            'color' => '#112233',
        ], 'api-write-user@example.com');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        // The other User never sees it: collection hides it, detail 404s
        // without disclosure.
        $this->client->request('GET', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-other@example.com'),
        ]);
        self::assertResponseIsSuccessful();
        self::assertNotContains('Mine', array_column(
            json_decode((string) $this->client->getResponse()->getContent(), true),
            'name',
        ));

        $this->client->request('GET', '/api/v1/expense-categories/'.$data['id'], [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-other@example.com'),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCreateIgnoresExtraFields(): void
    {
        $this->requestJson('POST', '/api/v1/expense-categories', [
            'name' => 'Food',
            'color' => '#ff0000',
            'id' => '0190a1b2-c3d4-7e5f-8901-23456789abcd',
            'owner' => 'api-write-other@example.com',
            'createdAt' => '2001-01-01T00:00:00+00:00',
        ], 'api-write-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNotSame('0190a1b2-c3d4-7e5f-8901-23456789abcd', $data['id']);
        self::assertNotSame('2001-01-01T00:00:00+00:00', $data['createdAt']);
    }

    public function testCreateWithInvalidNameReturns422WithFieldViolations(): void
    {
        foreach (['', 'A', str_repeat('n', 101)] as $badName) {
            $this->requestJson('POST', '/api/v1/expense-categories', [
                'name' => $badName,
                'color' => '#ff0000',
            ], 'api-write-user@example.com');

            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, sprintf('name %s must be rejected', var_export($badName, true)));
            self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
            $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertSame('validation_failed', $problem['code']);
            self::assertSame(422, $problem['status']);
            self::assertNotEmpty($this->violationFor($problem, 'name'));
        }
    }

    public function testCreateWithInvalidColorReturns422WithFieldViolations(): void
    {
        foreach (['red', '#fff', '#GGGGGG', 'ff0000', '#ff000', '#ff00000', ''] as $badColor) {
            $this->requestJson('POST', '/api/v1/expense-categories', [
                'name' => 'Valid Name',
                'color' => $badColor,
            ], 'api-write-user@example.com');

            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, sprintf('color %s must be rejected', var_export($badColor, true)));
            $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertSame('validation_failed', $problem['code']);
            self::assertNotEmpty($this->violationFor($problem, 'color'));
        }
    }

    public function testCreateWithMissingFieldsReturns422(): void
    {
        $this->requestJson('POST', '/api/v1/expense-categories', [], 'api-write-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('validation_failed', $problem['code']);
        self::assertNotEmpty($this->violationFor($problem, 'name'));
        self::assertNotEmpty($this->violationFor($problem, 'color'));
    }

    public function testCreateWithMalformedJsonReturns422(): void
    {
        $this->client->request('POST', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-user@example.com'),
            'CONTENT_TYPE' => 'application/json',
        ], '{not-json');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('validation_failed', $problem['code']);
    }

    public function testCreateWithJsonArrayBodyReturns422(): void
    {
        $this->client->request('POST', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-user@example.com'),
            'CONTENT_TYPE' => 'application/json',
        ], '["Food"]');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('validation_failed', $problem['code']);
    }

    public function testCreateWithDuplicateNameReturns409(): void
    {
        $categories = static::getContainer()->get(ExpenseCategoryService::class);
        $categories->create($this->user, 'Food', '#ff0000');

        $this->requestJson('POST', '/api/v1/expense-categories', [
            'name' => 'Food',
            'color' => '#00ff00',
        ], 'api-write-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('category_name_conflict', $problem['code']);
        self::assertSame(409, $problem['status']);
        self::assertArrayHasKey('type', $problem);
    }

    public function testCreateWithSameNameAsOtherUserReturns201(): void
    {
        $categories = static::getContainer()->get(ExpenseCategoryService::class);
        $categories->create($this->other, 'Food', '#ff0000');

        // Names are unique per User, not globally.
        $this->requestJson('POST', '/api/v1/expense-categories', [
            'name' => 'Food',
            'color' => '#00ff00',
        ], 'api-write-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testCreateWithoutTokenReturns401(): void
    {
        $this->requestJson('POST', '/api/v1/expense-categories', [
            'name' => 'Food',
            'color' => '#ff0000',
        ], null);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testPatchNameOnlyChangesName(): void
    {
        $category = static::getContainer()->get(ExpenseCategoryService::class)
            ->create($this->user, 'Food', '#ff0000');

        $this->requestJson('PATCH', '/api/v1/expense-categories/'.$category->getId(), [
            'name' => 'Groceries',
        ], 'api-write-user@example.com');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Groceries', $data['name']);
        self::assertSame('#ff0000', $data['color'], 'PATCH changes only supplied fields');
        self::assertSame((string) $category->getId(), $data['id']);
    }

    public function testPatchColorOnlyChangesColor(): void
    {
        $category = static::getContainer()->get(ExpenseCategoryService::class)
            ->create($this->user, 'Food', '#ff0000');

        $this->requestJson('PATCH', '/api/v1/expense-categories/'.$category->getId(), [
            'color' => '#00ff00',
        ], 'api-write-user@example.com');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Food', $data['name'], 'PATCH changes only supplied fields');
        self::assertSame('#00ff00', $data['color']);
    }

    public function testPatchWithInvalidValuesReturns422AndLeavesRecordUnchanged(): void
    {
        $category = static::getContainer()->get(ExpenseCategoryService::class)
            ->create($this->user, 'Food', '#ff0000');

        $this->requestJson('PATCH', '/api/v1/expense-categories/'.$category->getId(), [
            'name' => 'X',
            'color' => 'not-a-color',
        ], 'api-write-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('validation_failed', $problem['code']);
        self::assertNotEmpty($this->violationFor($problem, 'name'));
        self::assertNotEmpty($this->violationFor($problem, 'color'));

        $this->em->clear();
        $stored = $this->em->getRepository(\App\Entity\ExpenseCategory::class)->find($category->getId());
        self::assertSame('Food', $stored->getName());
        self::assertSame('#ff0000', $stored->getColor());
    }

    public function testPatchWithDuplicateNameReturns409(): void
    {
        $categories = static::getContainer()->get(ExpenseCategoryService::class);
        $categories->create($this->user, 'Taken', '#111111');
        $category = $categories->create($this->user, 'Food', '#ff0000');

        $this->requestJson('PATCH', '/api/v1/expense-categories/'.$category->getId(), [
            'name' => 'Taken',
        ], 'api-write-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('category_name_conflict', $problem['code']);
    }

    public function testPatchKeepingOwnNameReturns200(): void
    {
        $category = static::getContainer()->get(ExpenseCategoryService::class)
            ->create($this->user, 'Food', '#ff0000');

        $this->requestJson('PATCH', '/api/v1/expense-categories/'.$category->getId(), [
            'name' => 'Food',
            'color' => '#00ff00',
        ], 'api-write-user@example.com');

        self::assertResponseIsSuccessful();
    }

    public function testPatchWithEmptyObjectReturns422(): void
    {
        $category = static::getContainer()->get(ExpenseCategoryService::class)
            ->create($this->user, 'Food', '#ff0000');

        $this->requestJson('PATCH', '/api/v1/expense-categories/'.$category->getId(), [], 'api-write-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('validation_failed', $problem['code']);
    }

    public function testPatchIgnoresExtraFields(): void
    {
        $category = static::getContainer()->get(ExpenseCategoryService::class)
            ->create($this->user, 'Food', '#ff0000');

        $this->requestJson('PATCH', '/api/v1/expense-categories/'.$category->getId(), [
            'name' => 'Groceries',
            'owner' => 'api-write-other@example.com',
        ], 'api-write-user@example.com');

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $stored = $this->em->getRepository(\App\Entity\ExpenseCategory::class)->find($category->getId());
        self::assertSame('Groceries', $stored->getName());
        self::assertTrue($stored->isOwnedBy($this->freshUser('api-write-user@example.com')));
    }

    public function testPatchForeignIdReturns404WithoutDisclosure(): void
    {
        $foreign = static::getContainer()->get(ExpenseCategoryService::class)
            ->create($this->other, 'Foreign', '#333333');

        $this->requestJson('PATCH', '/api/v1/expense-categories/'.$foreign->getId(), [
            'name' => 'Hijacked',
        ], 'api-write-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('category_not_found', $problem['code']);
        self::assertStringNotContainsStringIgnoringCase('Foreign', (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        $stored = $this->em->getRepository(\App\Entity\ExpenseCategory::class)->find($foreign->getId());
        self::assertSame('Foreign', $stored->getName());
    }

    public function testPatchMissingAndMalformedIdsReturn404(): void
    {
        $missing = (string) \Symfony\Component\Uid\Uuid::v4();

        foreach ([$missing, 'not-a-uuid'] as $badId) {
            $this->requestJson('PATCH', '/api/v1/expense-categories/'.$badId, [
                'name' => 'Nope',
            ], 'api-write-user@example.com');

            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, sprintf('id %s must 404', $badId));
            $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertSame('category_not_found', $problem['code']);
        }
    }

    public function testDeleteUnusedReturns204AndRemovesRecord(): void
    {
        $category = static::getContainer()->get(ExpenseCategoryService::class)
            ->create($this->user, 'Food', '#ff0000');
        $id = (string) $category->getId();

        $this->client->request('DELETE', '/api/v1/expense-categories/'.$id, [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-user@example.com'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->client->request('GET', '/api/v1/expense-categories/'.$id, [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-user@example.com'),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDeleteRespondsWithEmptyBody(): void
    {
        $category = static::getContainer()->get(ExpenseCategoryService::class)
            ->create($this->user, 'Ephemeral', '#654321');

        $this->client->request('DELETE', '/api/v1/expense-categories/'.$category->getId(), [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-user@example.com'),
        ]);

        // A 204 carries no body: not even a serialized null.
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
    }

    public function testDeleteCategoryInUseReturns409WithoutReassignment(): void
    {
        $category = static::getContainer()->get(ExpenseCategoryService::class)
            ->create($this->user, 'Food', '#ff0000');
        $subscription = $this->createSubscription($this->user);
        $subscription->setCategory($category);
        $this->em->flush();

        $this->client->request('DELETE', '/api/v1/expense-categories/'.$category->getId(), [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-user@example.com'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('category_in_use', $problem['code']);
        self::assertSame(409, $problem['status']);

        // Nothing was reassigned: the Subscription still points at the category.
        $this->em->clear();
        $storedCategory = $this->em->getRepository(\App\Entity\ExpenseCategory::class)->find($category->getId());
        self::assertNotNull($storedCategory);
        $storedSubscription = $this->em->getRepository(\App\Entity\Subscription::class)->find($subscription->getId());
        self::assertSame((string) $storedCategory->getId(), (string) $storedSubscription->getCategory()->getId());
    }

    public function testDefaultCategoryIsEditableAndDeletableWhenUnused(): void
    {
        $category = static::getContainer()->get(ExpenseCategoryService::class)
            ->create($this->user, \App\Entity\ExpenseCategory::DEFAULT_NAME, \App\Entity\ExpenseCategory::DEFAULT_COLOR);

        $this->requestJson('PATCH', '/api/v1/expense-categories/'.$category->getId(), [
            'name' => 'Renamed Default',
        ], 'api-write-user@example.com');
        self::assertResponseIsSuccessful();

        $this->client->request('DELETE', '/api/v1/expense-categories/'.$category->getId(), [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-user@example.com'),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    public function testDefaultCategoryInUseCannotBeDeleted(): void
    {
        $this->createSubscription($this->user);
        $default = $this->freshEm()->getRepository(\App\Entity\ExpenseCategory::class)
            ->findOneBy(['name' => \App\Entity\ExpenseCategory::DEFAULT_NAME]);
        self::assertNotNull($default);

        $this->client->request('DELETE', '/api/v1/expense-categories/'.$default->getId(), [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-user@example.com'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('category_in_use', $problem['code']);
    }

    public function testDeleteForeignMissingAndRepeatedIdsReturn404(): void
    {
        $foreign = static::getContainer()->get(ExpenseCategoryService::class)
            ->create($this->other, 'Foreign', '#333333');

        // Created up front: the test client reboots the kernel between
        // requests, so fixtures must exist before the first HTTP call.
        $temporaryId = (string) static::getContainer()->get(ExpenseCategoryService::class)
            ->create($this->user, 'Temporary', '#123456')->getId();

        $this->client->request('DELETE', '/api/v1/expense-categories/'.$foreign->getId(), [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-user@example.com'),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('category_not_found', $problem['code']);

        $missing = (string) \Symfony\Component\Uid\Uuid::v4();
        $this->client->request('DELETE', '/api/v1/expense-categories/'.$missing, [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-user@example.com'),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->client->request('DELETE', '/api/v1/expense-categories/not-a-uuid', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-user@example.com'),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // Deleting twice: the second DELETE 404s.
        $this->client->request('DELETE', '/api/v1/expense-categories/'.$temporaryId, [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-user@example.com'),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->client->request('DELETE', '/api/v1/expense-categories/'.$temporaryId, [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-user@example.com'),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDeleteWithoutTokenReturns401(): void
    {
        $category = static::getContainer()->get(ExpenseCategoryService::class)
            ->create($this->user, 'Food', '#ff0000');

        $this->client->request('DELETE', '/api/v1/expense-categories/'.$category->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testOpenApiDocumentCoversWriteOperations(): void
    {
        $this->client->request('GET', '/api/v1/openapi.json', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-user@example.com'),
        ]);

        self::assertResponseIsSuccessful();
        $doc = json_decode((string) $this->client->getResponse()->getContent(), true);

        $collection = $doc['paths']['/expense-categories'];
        self::assertArrayHasKey('post', $collection);
        foreach (['201', '401', '403', '409', '422'] as $status) {
            self::assertArrayHasKey($status, $collection['post']['responses'], sprintf('POST responses must document %s', $status));
        }

        $detail = $doc['paths']['/expense-categories/{id}'];
        foreach (['patch', 'delete'] as $method) {
            self::assertArrayHasKey($method, $detail, sprintf('detail path must document %s', $method));
        }
        foreach (['200', '401', '403', '404', '409', '422'] as $status) {
            self::assertArrayHasKey($status, $detail['patch']['responses'], sprintf('PATCH responses must document %s', $status));
        }
        foreach (['204', '401', '403', '404', '409'] as $status) {
            self::assertArrayHasKey($status, $detail['delete']['responses'], sprintf('DELETE responses must document %s', $status));
        }

        // Write schemas accept name/color only; the read schema stays full.
        $create = $doc['components']['schemas']['ExpenseCategoryCreate'];
        self::assertContains('name', $create['required']);
        self::assertContains('color', $create['required']);
        self::assertArrayNotHasKey('id', $create['properties']);
        self::assertArrayNotHasKey('owner', $create['properties']);

        $update = $doc['components']['schemas']['ExpenseCategoryUpdate'];
        self::assertArrayHasKey('name', $update['properties']);
        self::assertArrayHasKey('color', $update['properties']);
        self::assertArrayNotHasKey('id', $update['properties']);

        foreach (['ValidationFailed', 'CategoryNameConflict', 'CategoryInUse'] as $name) {
            self::assertArrayHasKey($name, $doc['components']['responses'], sprintf('components.responses must define %s', $name));
            self::assertArrayHasKey(
                'application/problem+json',
                $doc['components']['responses'][$name]['content'],
                sprintf('%s must use application/problem+json', $name),
            );
        }
    }

    /**
     * @param array<string, mixed> $problem
     */
    private function violationFor(array $problem, string $field): ?string
    {
        foreach ($problem['errors'] ?? [] as $violation) {
            if (($violation['field'] ?? null) === $field) {
                return (string) ($violation['message'] ?? '');
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requestJson(string $method, string $uri, array $payload, ?string $userIdentifier): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $userIdentifier) {
            $server['HTTP_Authorization'] = 'Bearer '.$this->craftToken($userIdentifier);
        }

        // FORCE_OBJECT so an empty payload encodes as `{}` (a field-less
        // object), not `[]` (a JSON array, which the API rejects as malformed).
        $this->client->request($method, $uri, [], [], $server, json_encode($payload, JSON_FORCE_OBJECT));
    }
}
