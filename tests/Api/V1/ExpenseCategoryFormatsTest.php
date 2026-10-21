<?php

declare(strict_types=1);

namespace App\Tests\Api\V1;

use App\ApiResource\ExpenseCategory;
use App\Entity\User;
use App\Service\ExpenseCategoryService;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Content negotiation for API v1 ExpenseCategory: item and collection in
 * every enabled format, each checked against the JSON Schema API Platform
 * generates for that format, so the serializer and the published contract
 * cannot drift apart.
 */
final class ExpenseCategoryFormatsTest extends ApiV1TestCase
{
    private const URL = '/api/v1/expense-categories';

    private User $user;
    private string $itemUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('api-cat-formats@example.com');
        $categories = static::getContainer()->get(ExpenseCategoryService::class);
        $categories->create($this->user, 'Rent', '#111111');
        $this->itemUrl = self::URL.'/'.$categories->create($this->user, 'Food', '#ff0000')->getId();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function formats(): iterable
    {
        yield 'HAL' => ['jsonhal', 'application/hal+json'];
        yield 'plain JSON' => ['json', 'application/json'];
        yield 'JSON-LD' => ['jsonld', 'application/ld+json'];
    }

    #[DataProvider('formats')]
    public function testItemMatchesItsSchema(string $format, string $mimeType): void
    {
        $this->requestAs($this->user, 'GET', $this->itemUrl, ['headers' => ['accept' => $mimeType]]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', $mimeType);
        self::assertMatchesResourceItemJsonSchema(ExpenseCategory::class, format: $format);
    }

    #[DataProvider('formats')]
    public function testCollectionMatchesItsSchema(string $format, string $mimeType): void
    {
        $this->requestAs($this->user, 'GET', self::URL, ['headers' => ['accept' => $mimeType]]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', $mimeType);
        self::assertMatchesResourceCollectionJsonSchema(ExpenseCategory::class, format: $format);
    }

    public function testPlainJsonCollectionIsABareArray(): void
    {
        $data = $this->requestAs($this->user, 'GET', self::URL, ['headers' => ['accept' => 'application/json']])->toArray();

        self::assertTrue(array_is_list($data));
        self::assertSame(['Food', 'Rent'], array_column($data, 'name'));
        self::assertSame(['id', 'name', 'color', 'createdAt', 'updatedAt'], array_keys($data[0]));
    }

    public function testJsonLdItemCarriesIdentifiersNextToItsId(): void
    {
        $data = $this->requestAs($this->user, 'GET', $this->itemUrl, ['headers' => ['accept' => 'application/ld+json']])->toArray();

        self::assertSame($this->itemUrl, $data['@id']);
        self::assertSame('ExpenseCategory', $data['@type']);
        self::assertSame(substr($this->itemUrl, \strlen(self::URL) + 1), $data['id']);
    }

    public function testHalIsTheDefaultFormat(): void
    {
        $this->requestAs($this->user, 'GET', $this->itemUrl, ['headers' => ['accept' => '*/*']]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/hal+json');
    }

    public function testUnsupportedAcceptIsNotAcceptable(): void
    {
        $this->requestAs($this->user, 'GET', $this->itemUrl, ['headers' => ['accept' => 'application/xml']]);

        self::assertResponseStatusCodeSame(406);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }
}
