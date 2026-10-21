<?php

declare(strict_types=1);

namespace App\Tests\Api\V1;

use App\ApiResource\Subscription;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Content negotiation for API v1 Subscription: item and collection in every
 * enabled format, each checked against the JSON Schema API Platform
 * generates for that format, so the serializer and the published contract
 * cannot drift apart.
 */
final class SubscriptionFormatsTest extends ApiV1TestCase
{
    private const URL = '/api/v1/subscriptions';

    private User $user;
    private string $itemUrl;
    private string $categoryIri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('api-sub-formats@example.com');
        $subscription = $this->createSubscription($this->user);
        $this->itemUrl = self::URL.'/'.$subscription->getId();
        $this->categoryIri = '/api/v1/expense-categories/'.$subscription->getCategory()->getId();
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
        self::assertMatchesResourceItemJsonSchema(Subscription::class, format: $format);
    }

    #[DataProvider('formats')]
    public function testCollectionMatchesItsSchema(string $format, string $mimeType): void
    {
        $this->requestAs($this->user, 'GET', self::URL, ['headers' => ['accept' => $mimeType]]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', $mimeType);
        self::assertMatchesResourceCollectionJsonSchema(Subscription::class, format: $format);
    }

    public function testPlainJsonItemCarriesCategoryIriAndDecimalMoney(): void
    {
        $data = $this->requestAs($this->user, 'GET', $this->itemUrl, ['headers' => ['accept' => 'application/json']])->toArray();

        self::assertTrue(array_is_list($data) === false);
        self::assertSame('15.99', $data['amount']);
        self::assertSame($this->categoryIri, (string) $data['category']);
        self::assertArrayNotHasKey('_links', $data);
    }

    public function testPlainJsonCollectionIsABareArray(): void
    {
        $this->createSubscription($this->user, 'Alpha');
        $data = $this->requestAs($this->user, 'GET', self::URL, ['headers' => ['accept' => 'application/json']])->toArray();

        self::assertTrue(array_is_list($data));
        self::assertSame(['Alpha', 'Netflix'], array_column($data, 'name'));
        foreach (['id', 'name', 'billingCycle', 'amount', 'nextPayment', 'category', 'pendingReview', 'createdAt', 'updatedAt'] as $field) {
            self::assertArrayHasKey($field, $data[0], sprintf('Expected field "%s" in plain JSON row', $field));
        }
        self::assertSame($this->categoryIri, (string) $data[1]['category']);
    }

    public function testJsonLdItemCarriesIdentifiersNextToItsId(): void
    {
        $data = $this->requestAs($this->user, 'GET', $this->itemUrl, ['headers' => ['accept' => 'application/ld+json']])->toArray();

        self::assertSame($this->itemUrl, $data['@id']);
        self::assertSame('Subscription', $data['@type']);
        self::assertSame(substr($this->itemUrl, \strlen(self::URL) + 1), $data['id']);
    }

    public function testHalIsTheDefaultFormat(): void
    {
        $this->requestAs($this->user, 'GET', $this->itemUrl, ['headers' => ['accept' => '*/*']]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/hal+json');
    }

    public function testHalCollectionHasPaginationLinks(): void
    {
        $data = $this->requestAs($this->user, 'GET', self::URL)->toArray();

        // A single page needs no page parameter; multi-page collections
        // carry first/last/prev/next links (see SubscriptionTest).
        self::assertSame(['href' => self::URL], $data['_links']['self']);
        self::assertSame(1, $data['totalItems']);
        self::assertSame(25, $data['itemsPerPage']);
        self::assertCount(1, $data['_embedded']['item']);
        self::assertArrayHasKey('category', $data['_embedded']['item'][0]['_links']);
    }

    public function testUnsupportedAcceptIsNotAcceptable(): void
    {
        $this->requestAs($this->user, 'GET', $this->itemUrl, ['headers' => ['accept' => 'application/xml']]);

        self::assertResponseStatusCodeSame(406);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

}
