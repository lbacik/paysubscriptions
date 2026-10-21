<?php

declare(strict_types=1);

namespace App\Tests\Api\V1;

use App\ApiResource\DashboardReport;
use App\Entity\User;
use App\Enum\BillingCycle;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Content negotiation for the API v1 dashboard report: the item in every
 * enabled format, each checked against the JSON Schema API Platform
 * generates for that format, so the serializer and the published contract
 * cannot drift apart.
 *
 * Money stays a decimal string in every format; only the envelope changes.
 */
final class DashboardReportFormatsTest extends ApiV1TestCase
{
    private const URL = '/api/v1/reports/dashboard';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('api-report-formats@example.com');
        $this->user->setMainCurrency('USD');
        $this->em->flush();
        $this->createSubscription($this->user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-01-15'));
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
        $this->requestAs($this->user, 'GET', self::URL, ['headers' => ['accept' => $mimeType]]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', $mimeType);
        self::assertMatchesResourceItemJsonSchema(DashboardReport::class, format: $format);
    }

    #[DataProvider('formats')]
    public function testMoneyIsDecimalStringsWhateverTheEnvelope(string $format, string $mimeType): void
    {
        $data = $this->requestAs($this->user, 'GET', self::URL, ['headers' => ['accept' => $mimeType]])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('15.99', $data['summary']['monthly']);
        self::assertSame('15.99', $data['summary']['monthlyEquivalent']);
        self::assertSame('15.99', $data['chart']['datasets'][0]['data'][0]);
        // JSON-LD decorates even nested objects with @id/@type; the selectors stay the same.
        self::assertSame('bar', $data['filters']['chartMode']);
        self::assertSame('direct', $data['filters']['basis']);
    }

    public function testJsonLdItemCarriesIdentifiersNextToItsReport(): void
    {
        $data = $this->requestAs($this->user, 'GET', self::URL, ['headers' => ['accept' => 'application/ld+json']])->toArray();

        self::assertSame(self::URL, $data['@id']);
        self::assertSame('DashboardReport', $data['@type']);
    }

    public function testHalIsTheDefaultFormat(): void
    {
        $this->requestAs($this->user, 'GET', self::URL, ['headers' => ['accept' => '*/*']]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/hal+json');
    }

    public function testUnsupportedAcceptIsNotAcceptable(): void
    {
        $this->requestAs($this->user, 'GET', self::URL, ['headers' => ['accept' => 'application/xml']]);

        self::assertResponseStatusCodeSame(406);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }
}
