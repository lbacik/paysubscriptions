<?php

declare(strict_types=1);

namespace App\Tests\Api\V1;

use App\Entity\User;
use App\Service\ExpenseCategoryService;

/**
 * Strict contract conformance (issue #277, parent #276): real API v1
 * responses validated against the published document with undeclared members
 * forbidden and `required` enforced at test time.
 *
 * Each case uses the representation the Canonical Client Spec policy picks:
 * plain JSON for any response carrying one resource, HAL for collections.
 * The schemas come from the published document served over HTTP (never from
 * decorator internals), so any serializer/schema drift fails CI.
 */
final class ContractConformanceTest extends ApiV1TestCase
{
    private const SUBSCRIPTIONS = '/api/v1/subscriptions';
    private const CATEGORIES = '/api/v1/expense-categories';
    private const DASHBOARD = '/api/v1/reports/dashboard';
    private const MERGE_PATCH = ['content-type' => 'application/merge-patch+json'];
    private const HAL = 'application/hal+json';
    private const JSON = 'application/json';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('api-contract@example.com');
        $this->user->setMainCurrency('USD');
        $this->em->flush();
    }

    public function testSubscriptionCollectionPagesConformInHal(): void
    {
        for ($i = 1; $i <= 26; ++$i) {
            $this->createSubscription($this->user, sprintf('Sub-%02d', $i));
        }

        // Page 1 carries a `next` link, page 2 carries `prev`.
        $first = $this->requestAs($this->user, 'GET', self::SUBSCRIPTIONS, ['headers' => ['accept' => self::HAL]])->toArray();
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', self::HAL);
        self::assertArrayHasKey('next', $first['_links']);
        OpenApiContract::assertMatchesStrictly(
            OpenApiContract::responseSchema($this->client, 'api_v1_subscriptions_list', 200, self::HAL),
            $first,
        );

        $second = $this->requestAs($this->user, 'GET', self::SUBSCRIPTIONS.'?page=2', ['headers' => ['accept' => self::HAL]])->toArray();
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('prev', $second['_links'], 'Page 2 must carry the `prev` link the contract declares.');
        OpenApiContract::assertMatchesStrictly(
            OpenApiContract::responseSchema($this->client, 'api_v1_subscriptions_list', 200, self::HAL),
            $second,
        );

        // An out-of-range page has no `_embedded` key at all.
        $empty = $this->requestAs($this->user, 'GET', self::SUBSCRIPTIONS.'?page=99', ['headers' => ['accept' => self::HAL]])->toArray();
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('_embedded', $empty, 'An empty page must stay valid although `_embedded` is absent.');
        OpenApiContract::assertMatchesStrictly(
            OpenApiContract::responseSchema($this->client, 'api_v1_subscriptions_list', 200, self::HAL),
            $empty,
        );
    }

    public function testExpenseCategoryCollectionConformsInHal(): void
    {
        $categories = static::getContainer()->get(ExpenseCategoryService::class);
        $categories->create($this->user, 'Rent', '#111111');
        $categories->create($this->user, 'Food', '#ff0000');

        $data = $this->requestAs($this->user, 'GET', self::CATEGORIES, ['headers' => ['accept' => self::HAL]])->toArray();
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', self::HAL);

        OpenApiContract::assertMatchesStrictly(
            OpenApiContract::responseSchema($this->client, 'api_v1expense-categories_get_collection', 200, self::HAL),
            $data,
        );
    }

    public function testSubscriptionItemConformsInPlainJson(): void
    {
        $subscription = $this->createSubscription($this->user);

        $data = $this->requestAs($this->user, 'GET', self::SUBSCRIPTIONS.'/'.$subscription->getId(), ['headers' => ['accept' => self::JSON]])->toArray();
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', self::JSON);

        OpenApiContract::assertMatchesStrictly(
            OpenApiContract::responseSchema($this->client, 'api_v1_subscriptions_show', 200, self::JSON),
            $data,
        );
    }

    public function testSubscriptionCreateConformsInPlainJson(): void
    {
        $category = static::getContainer()->get(ExpenseCategoryService::class)->create($this->user, 'Video', '#111111');

        $data = $this->requestAs($this->user, 'POST', self::SUBSCRIPTIONS, ['json' => [
            'name' => 'Contract Sub',
            'billingCycle' => 'monthly',
            'amount' => '10.00',
            'nextPayment' => '2026-04-05',
            'currency' => 'USD',
            'category' => self::CATEGORIES.'/'.$category->getId(),
        ], 'headers' => ['accept' => self::JSON]])->toArray();
        self::assertResponseStatusCodeSame(201);

        OpenApiContract::assertMatchesStrictly(
            OpenApiContract::responseSchema($this->client, 'api_v1_subscriptions_create', 201, self::JSON),
            $data,
        );
    }

    public function testSubscriptionUpdateConformsInPlainJson(): void
    {
        $subscription = $this->createSubscription($this->user);
        // The processor requires a currency on update; the fixture leaves
        // it empty.
        $subscription->setCurrency('USD');
        $this->em->flush();

        $data = $this->requestAs($this->user, 'PATCH', self::SUBSCRIPTIONS.'/'.$subscription->getId(), [
            'json' => ['name' => 'Renamed Sub'],
            'headers' => self::MERGE_PATCH + ['accept' => self::JSON],
        ])->toArray();
        self::assertResponseIsSuccessful();

        OpenApiContract::assertMatchesStrictly(
            OpenApiContract::responseSchema($this->client, 'api_v1_subscriptions_update', 200, self::JSON),
            $data,
        );
    }

    public function testExpenseCategoryItemConformsInPlainJson(): void
    {
        $category = static::getContainer()->get(ExpenseCategoryService::class)->create($this->user, 'Food', '#ff0000');

        $data = $this->requestAs($this->user, 'GET', self::CATEGORIES.'/'.$category->getId(), ['headers' => ['accept' => self::JSON]])->toArray();
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', self::JSON);

        OpenApiContract::assertMatchesStrictly(
            OpenApiContract::responseSchema($this->client, 'api_v1expense-categories_id_get', 200, self::JSON),
            $data,
        );
    }

    public function testExpenseCategoryCreateConformsInPlainJson(): void
    {
        $data = $this->requestAs($this->user, 'POST', self::CATEGORIES, [
            'json' => ['name' => 'Travel', 'color' => '#123456'],
            'headers' => ['accept' => self::JSON],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);

        OpenApiContract::assertMatchesStrictly(
            OpenApiContract::responseSchema($this->client, 'api_v1expense-categories_post', 201, self::JSON),
            $data,
        );
    }

    public function testExpenseCategoryUpdateConformsInPlainJson(): void
    {
        $category = static::getContainer()->get(ExpenseCategoryService::class)->create($this->user, 'Food', '#ff0000');

        $data = $this->requestAs($this->user, 'PATCH', self::CATEGORIES.'/'.$category->getId(), [
            'json' => ['name' => 'Groceries'],
            'headers' => self::MERGE_PATCH + ['accept' => self::JSON],
        ])->toArray();
        self::assertResponseIsSuccessful();

        OpenApiContract::assertMatchesStrictly(
            OpenApiContract::responseSchema($this->client, 'api_v1expense-categories_id_patch', 200, self::JSON),
            $data,
        );
    }

    public function testDashboardReportConformsInPlainJson(): void
    {
        $this->createSubscription($this->user);

        $data = $this->requestAs($this->user, 'GET', self::DASHBOARD, ['headers' => ['accept' => self::JSON]])->toArray();
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', self::JSON);

        OpenApiContract::assertMatchesStrictly(
            OpenApiContract::responseSchema($this->client, 'api_v1_reports_dashboard', 200, self::JSON),
            $data,
        );
    }
}
