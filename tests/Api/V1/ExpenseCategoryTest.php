<?php

declare(strict_types=1);

namespace App\Tests\Api\V1;

use App\Entity\ExpenseCategory;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\ExpenseCategoryService;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;

/**
 * API v1 ExpenseCategory behavior in the default format (HAL): reads, writes
 * through ExpenseCategoryService, the error contract, and two-User isolation.
 * Content negotiation is covered by ExpenseCategoryFormatsTest, the token
 * gate by TokenGateTest.
 */
final class ExpenseCategoryTest extends ApiV1TestCase
{
    private const URL = '/api/v1/expense-categories';
    private const MERGE_PATCH = ['content-type' => 'application/merge-patch+json'];

    private User $user;
    private User $other;
    private ExpenseCategoryService $categories;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('api-cat-user@example.com');
        $this->other = $this->createUser('api-cat-other@example.com');
        $this->categories = static::getContainer()->get(ExpenseCategoryService::class);
    }

    public function testCollectionListsOwnedCategoriesByNameUnpaginated(): void
    {
        $zebra = $this->categories->create($this->user, 'Zebra', '#111111');
        $apple = $this->categories->create($this->user, 'Apple', '#222222');
        $this->categories->create($this->other, 'Secret Stash', '#333333');

        $response = $this->requestAs($this->user, 'GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/hal+json');
        self::assertJsonContains([
            '_links' => ['self' => ['href' => self::URL]],
            'totalItems' => 2,
        ]);
        $data = $response->toArray();
        self::assertSame(['Apple', 'Zebra'], array_column($data['_embedded']['item'], 'name'));
        self::assertSame([(string) $apple->getId(), (string) $zebra->getId()], array_column($data['_embedded']['item'], 'id'));
        self::assertArrayNotHasKey('next', $data['_links'], 'The category list is not paginated.');
        self::assertStringNotContainsString('Secret Stash', $response->getContent());
    }

    public function testItemExposesIdLinkFieldsAndTimestampsButNoOwner(): void
    {
        $category = $this->categories->create($this->user, 'Food', '#ff0000');
        $iri = self::URL.'/'.$category->getId();

        $data = $this->requestAs($this->user, 'GET', $iri)->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame(['href' => $iri], $data['_links']['self']);
        self::assertSame((string) $category->getId(), $data['id']);
        self::assertSame('Food', $data['name']);
        self::assertSame('#ff0000', $data['color']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $data['createdAt']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $data['updatedAt']);
        self::assertSame(['_links', 'id', 'name', 'color', 'createdAt', 'updatedAt'], array_keys($data));
    }

    public function testMissingOrMalformedIdIsNotFound(): void
    {
        $this->requestAs($this->user, 'GET', self::URL.'/'.Uuid::v4());
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertJsonContains(['type' => '/errors/404', 'status' => 404]);

        $this->requestAs($this->user, 'GET', self::URL.'/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testCreateReturns201WithLocationAndOwnsTheCategoryForTheTokenUser(): void
    {
        $data = $this->requestAs($this->user, 'POST', self::URL, ['json' => ['name' => 'Food', 'color' => '#ff0000']])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('location', self::URL.'/'.$data['id']);
        self::assertSame('Food', $data['name']);
        self::assertSame('#ff0000', $data['color']);
        self::assertArrayHasKey('createdAt', $data);

        $this->em->clear();
        $stored = $this->em->getRepository(ExpenseCategory::class)->find(Uuid::fromString($data['id']));
        self::assertNotNull($stored);
        self::assertTrue($stored->isOwnedBy($this->user));
    }

    public function testCreateIgnoresReadOnlyAndUnknownMembers(): void
    {
        $data = $this->requestAs($this->user, 'POST', self::URL, ['json' => [
            'name' => 'Travel',
            'color' => '#123456',
            'createdAt' => '2000-01-01T00:00:00+00:00',
            'owner' => '/api/v1/users/'.$this->other->getId(),
        ]])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertStringStartsNotWith('2000-', $data['createdAt']);

        $this->em->clear();
        $stored = $this->em->getRepository(ExpenseCategory::class)->find(Uuid::fromString($data['id']));
        self::assertTrue($stored->isOwnedBy($this->user));
    }

    public function testCreateWithIdInBodyIsBadRequest(): void
    {
        $this->requestAs($this->user, 'POST', self::URL, ['json' => ['id' => (string) Uuid::v4(), 'name' => 'Travel', 'color' => '#123456']]);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testCreateWithInvalidFieldsReturns422WithViolations(): void
    {
        $this->requestAs($this->user, 'POST', self::URL, ['json' => ['name' => 'F', 'color' => 'red']]);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame(['color', 'name'], $this->violatedPaths());
        self::assertSame(0, $this->countCategories($this->user));
    }

    public function testCreateWithMissingFieldsReturns422(): void
    {
        $this->requestAs($this->user, 'POST', self::URL, ['json' => []]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['color', 'name'], $this->violatedPaths());
    }

    public function testCreateWithWrongTypeReturns422(): void
    {
        $this->requestAs($this->user, 'POST', self::URL, ['json' => ['name' => 123, 'color' => '#000000']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['name'], $this->violatedPaths());
    }

    public function testCreateWithMalformedJsonIsBadRequest(): void
    {
        $this->requestAs($this->user, 'POST', self::URL, ['body' => '{"name":', 'headers' => ['content-type' => 'application/json']]);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertJsonContains(['type' => '/errors/400']);
    }

    public function testCreateWithTakenNameReturns422OnName(): void
    {
        $this->categories->create($this->user, 'Rent', '#111111');

        $this->requestAs($this->user, 'POST', self::URL, ['json' => ['name' => 'Rent', 'color' => '#ff0000']]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [[
            'propertyPath' => 'name',
            'message' => 'You already have a category with name "Rent".',
            'code' => UniqueEntity::NOT_UNIQUE_ERROR,
        ]]]);
        self::assertJsonContains(['type' => '/validation_errors/'.UniqueEntity::NOT_UNIQUE_ERROR]);
        self::assertSame(1, $this->countCategories($this->user));
    }

    public function testCreateWithNameTakenOnlyByAnotherUserSucceeds(): void
    {
        $this->categories->create($this->other, 'Secret', '#111111');

        $this->requestAs($this->user, 'POST', self::URL, ['json' => ['name' => 'Secret', 'color' => '#ff0000']]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testPatchChangesOnlySuppliedMembers(): void
    {
        $category = $this->categories->create($this->user, 'Food', '#ff0000');
        $iri = self::URL.'/'.$category->getId();

        $this->requestAs($this->user, 'PATCH', $iri, ['json' => ['name' => 'Groceries'], 'headers' => self::MERGE_PATCH]);
        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Groceries', 'color' => '#ff0000']);

        $this->requestAs($this->user, 'PATCH', $iri, ['json' => ['color' => '#00ff00'], 'headers' => self::MERGE_PATCH]);
        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Groceries', 'color' => '#00ff00']);

        $stored = $this->storedCategory($category);
        self::assertSame('Groceries', $stored->getName());
        self::assertSame('#00ff00', $stored->getColor());
    }

    public function testPatchWithEmptyObjectIsANoOp(): void
    {
        $category = $this->categories->create($this->user, 'Food', '#ff0000');

        $this->requestAs($this->user, 'PATCH', self::URL.'/'.$category->getId(), ['json' => new \stdClass(), 'headers' => self::MERGE_PATCH]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Food', 'color' => '#ff0000']);
    }

    public function testPatchKeepingOwnNameSucceeds(): void
    {
        $category = $this->categories->create($this->user, 'Food', '#ff0000');

        $this->requestAs($this->user, 'PATCH', self::URL.'/'.$category->getId(), ['json' => ['name' => 'Food', 'color' => '#00ff00'], 'headers' => self::MERGE_PATCH]);

        self::assertResponseIsSuccessful();
        self::assertSame('#00ff00', $this->storedCategory($category)->getColor());
    }

    public function testPatchWithInvalidOrNullValuesReturns422AndChangesNothing(): void
    {
        $category = $this->categories->create($this->user, 'Food', '#ff0000');
        $iri = self::URL.'/'.$category->getId();

        $this->requestAs($this->user, 'PATCH', $iri, ['json' => ['name' => 'F', 'color' => 'red'], 'headers' => self::MERGE_PATCH]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['color', 'name'], $this->violatedPaths());

        $this->requestAs($this->user, 'PATCH', $iri, ['json' => ['name' => null], 'headers' => self::MERGE_PATCH]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['name'], $this->violatedPaths());

        $stored = $this->storedCategory($category);
        self::assertSame('Food', $stored->getName());
        self::assertSame('#ff0000', $stored->getColor());
    }

    public function testPatchToTakenNameReturns422OnNameAndChangesNothing(): void
    {
        $this->categories->create($this->user, 'Rent', '#111111');
        $category = $this->categories->create($this->user, 'Food', '#ff0000');

        $this->requestAs($this->user, 'PATCH', self::URL.'/'.$category->getId(), ['json' => ['name' => 'Rent', 'color' => '#00ff00'], 'headers' => self::MERGE_PATCH]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [[
            'propertyPath' => 'name',
            'message' => 'You already have a category with name "Rent".',
            'code' => UniqueEntity::NOT_UNIQUE_ERROR,
        ]]]);
        $stored = $this->storedCategory($category);
        self::assertSame('Food', $stored->getName());
        self::assertSame('#ff0000', $stored->getColor());
    }

    public function testPatchRequiresMergePatchContentType(): void
    {
        $category = $this->categories->create($this->user, 'Food', '#ff0000');

        $this->requestAs($this->user, 'PATCH', self::URL.'/'.$category->getId(), ['json' => ['color' => '#00ff00']]);

        self::assertResponseStatusCodeSame(415);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testDeleteUnusedCategoryReturns204WithEmptyBody(): void
    {
        $category = $this->categories->create($this->user, 'Food', '#ff0000');

        $response = $this->requestAs($this->user, 'DELETE', self::URL.'/'.$category->getId());

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $response->getContent());
        self::assertSame(0, $this->countCategories($this->user));

        $this->requestAs($this->user, 'DELETE', self::URL.'/'.$category->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteCategoryInUseIsConflictWithoutReassignment(): void
    {
        $subscription = $this->createSubscription($this->user);
        $category = $subscription->getCategory();
        \assert($category instanceof ExpenseCategory);

        $this->requestAs($this->user, 'DELETE', self::URL.'/'.$category->getId());

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertJsonContains([
            'type' => '/problems/category-in-use',
            'title' => 'Expense category in use',
            'status' => 409,
            'detail' => 'Category "Subscriptions" cannot be deleted because it still has 1 subscription(s) assigned. Reassign them first.',
        ]);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Subscription::class)->find($subscription->getId());
        self::assertSame((string) $category->getId(), (string) $reloaded->getCategory()?->getId());
    }

    public function testDefaultCategoryIsEditableAndDeletableWhenUnused(): void
    {
        $default = $this->categories->ensureDefaultCategory($this->user);
        $iri = self::URL.'/'.$default->getId();

        $this->requestAs($this->user, 'PATCH', $iri, ['json' => ['name' => 'Streaming'], 'headers' => self::MERGE_PATCH]);
        self::assertResponseIsSuccessful();

        $this->requestAs($this->user, 'DELETE', $iri);
        self::assertResponseStatusCodeSame(204);
    }

    public function testErrorsAreProblemJsonWhateverTheAccept(): void
    {
        foreach (['application/json', 'application/ld+json', 'application/hal+json'] as $accept) {
            $this->requestAs($this->user, 'GET', self::URL.'/'.Uuid::v4(), ['headers' => ['accept' => $accept]]);

            self::assertResponseStatusCodeSame(404);
            self::assertResponseHeaderSame('content-type', 'application/problem+json', $accept);
        }
    }

    public function testCollectionNeverShowsForeignRows(): void
    {
        $this->categories->create($this->other, 'Secret Stash', '#333333');

        $response = $this->requestAs($this->user, 'GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['totalItems' => 0]);
        self::assertStringNotContainsString('Secret Stash', $response->getContent());
    }

    public function testForeignIdIsNotFoundForEveryItemOperationWithoutDisclosure(): void
    {
        $foreign = $this->categories->create($this->other, 'Secret Stash', '#333333');
        $iri = self::URL.'/'.$foreign->getId();

        $responses = [
            'GET' => $this->requestAs($this->user, 'GET', $iri),
            'PATCH' => $this->requestAs($this->user, 'PATCH', $iri, ['json' => ['name' => 'Mine now'], 'headers' => self::MERGE_PATCH]),
            'DELETE' => $this->requestAs($this->user, 'DELETE', $iri),
        ];

        foreach ($responses as $method => $response) {
            self::assertSame(404, $response->getStatusCode(), $method);
            self::assertStringNotContainsString('Secret Stash', $response->getContent(false), $method);
        }

        $stored = $this->storedCategory($foreign);
        self::assertSame('Secret Stash', $stored->getName());
        self::assertTrue($stored->isOwnedBy($this->other));
    }

    /**
     * @return list<string> the violated property paths of the last response, sorted
     */
    private function violatedPaths(): array
    {
        $paths = array_column(json_decode((string) $this->client->getKernelBrowser()->getResponse()->getContent(), true)['violations'] ?? [], 'propertyPath');
        sort($paths);

        return array_values(array_unique($paths));
    }

    private function storedCategory(ExpenseCategory $category): ExpenseCategory
    {
        $this->em->clear();
        $stored = $this->em->getRepository(ExpenseCategory::class)->find($category->getId());
        self::assertNotNull($stored);

        return $stored;
    }

    private function countCategories(User $owner): int
    {
        $this->em->clear();

        return $this->em->getRepository(ExpenseCategory::class)->count(['owner' => $owner->getId()]);
    }
}
