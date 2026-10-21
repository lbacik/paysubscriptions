<?php

declare(strict_types=1);

namespace App\Tests\Api\V1;

use App\Entity\ExpenseCategory;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use App\Service\ExpenseCategoryService;
use Symfony\Component\Uid\Uuid;

/**
 * API v1 Subscription behavior in the default format (HAL): reads, writes
 * through SubscriptionService, the error contract, and two-User isolation.
 * Content negotiation is covered by SubscriptionFormatsTest, the token gate
 * by TokenGateTest.
 *
 * Contract notes: `category` is an expense-category IRI on write and a
 * `_links.category` link on read (it replaces `categoryId`); money travels
 * as decimal strings; the collection is paginated (25 per page, 1-based
 * `page`), sorted by a single `order[name|price|renewal]` (default name
 * asc, ties by name then id) and filtered by `category=<uuid>`.
 */
final class SubscriptionTest extends ApiV1TestCase
{
    private const URL = '/api/v1/subscriptions';
    private const CATEGORIES = '/api/v1/expense-categories';
    private const MERGE_PATCH = ['content-type' => 'application/merge-patch+json'];

    private User $user;
    private User $other;
    private ExpenseCategoryService $categories;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('api-sub-user@example.com');
        $this->other = $this->createUser('api-sub-other@example.com');
        $this->categories = static::getContainer()->get(ExpenseCategoryService::class);
    }

    public function testCollectionReturnsOwnedSubscriptionsSortedByNameWithCategoryLinks(): void
    {
        $category = $this->categories->create($this->user, 'Video', '#111111');
        $this->makeSubscription($this->user, $category, 'Bravo', BillingCycle::Monthly, '2026-03-05', 20.0);
        $this->makeSubscription($this->user, $category, 'Alpha', BillingCycle::Monthly, '2026-03-06', 10.0);
        $foreignCategory = $this->categories->create($this->other, 'Foreign', '#222222');
        $this->makeSubscription($this->other, $foreignCategory, 'ForeignFlix', BillingCycle::Monthly, '2026-03-05', 10.0);

        $response = $this->requestAs($this->user, 'GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/hal+json');
        self::assertJsonContains([
            '_links' => ['self' => ['href' => self::URL]],
            'totalItems' => 2,
            'itemsPerPage' => 25,
        ]);
        $data = $response->toArray();

        // Default sort is name ascending.
        self::assertSame(['Alpha', 'Bravo'], array_column($data['_embedded']['item'], 'name'));

        $first = $data['_embedded']['item'][0];
        self::assertSame(['_links', 'id', 'name', 'billingCycle', 'amount', 'nextPayment', 'pendingReview', 'createdAt', 'updatedAt'], array_keys($first));
        self::assertSame('monthly', $first['billingCycle']);
        self::assertSame('10.00', $first['amount']);
        self::assertSame('2026-03-06', $first['nextPayment']);
        self::assertFalse($first['pendingReview']);
        self::assertSame(['href' => self::URL.'/'.$first['id']], $first['_links']['self']);
        self::assertSame(['href' => self::CATEGORIES.'/'.$category->getId()], $first['_links']['category']);
        self::assertArrayNotHasKey('owner', $first);
        self::assertArrayNotHasKey('categoryId', $first);

        self::assertStringNotContainsString('ForeignFlix', $response->getContent());
    }

    public function testItemExposesConvertedMoneyNotesAndTimestamps(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->categories->create($this->user, 'Video', '#111111');
        $subscription = $this->makeSubscription(
            $this->user,
            $category,
            'CrossFlix',
            BillingCycle::Monthly,
            '2026-04-05',
            10.0,
            'EUR',
            11.0,
            'USD',
            'Family plan',
        );

        $data = $this->requestAs($this->user, 'GET', self::URL.'/'.$subscription->getId())->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame((string) $subscription->getId(), $data['id']);
        self::assertSame('CrossFlix', $data['name']);
        self::assertSame('monthly', $data['billingCycle']);
        self::assertSame('10.00', $data['amount']);
        self::assertSame('2026-04-05', $data['nextPayment']);
        self::assertSame('EUR', $data['currency']);
        self::assertSame('11.00', $data['convertedAmount']);
        self::assertSame('USD', $data['convertedCurrency']);
        self::assertFalse($data['pendingReview']);
        self::assertSame('Family plan', $data['notes']);
        self::assertSame(['href' => self::URL.'/'.$subscription->getId()], $data['_links']['self']);
        self::assertSame(['href' => self::CATEGORIES.'/'.$category->getId()], $data['_links']['category']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $data['createdAt']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $data['updatedAt']);
        self::assertArrayNotHasKey('owner', $data);
        self::assertArrayNotHasKey('categoryId', $data);
        self::assertArrayNotHasKey('confirmConvertedFor', $data);
    }

    public function testMissingForeignAndMalformedIdsAreProblemNotFound(): void
    {
        $foreignCategory = $this->categories->create($this->other, 'Foreign', '#222222');
        $foreign = $this->makeSubscription($this->other, $foreignCategory, 'ForeignFlix', BillingCycle::Monthly, '2026-03-05', 10.0);

        $missing = $this->requestAs($this->user, 'GET', self::URL.'/'.Uuid::v4());
        self::assertSame(404, $missing->getStatusCode());
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertJsonContains(['type' => '/errors/404', 'status' => 404]);

        $foreignResponse = $this->requestAs($this->user, 'GET', self::URL.'/'.$foreign->getId());
        self::assertSame(404, $foreignResponse->getStatusCode());
        self::assertStringNotContainsString('ForeignFlix', $foreignResponse->getContent(false));

        $this->requestAs($this->user, 'GET', self::URL.'/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCollectionPaginatesTwentyFivePerPage(): void
    {
        $category = $this->categories->create($this->user, 'Video', '#111111');
        for ($i = 1; $i <= 27; ++$i) {
            $this->makeSubscription($this->user, $category, sprintf('Sub-%02d', $i), BillingCycle::Monthly, '2026-03-05', 10.0);
        }

        $page1 = $this->requestAs($this->user, 'GET', self::URL)->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame(27, $page1['totalItems']);
        self::assertSame(25, $page1['itemsPerPage']);
        self::assertCount(25, $page1['_embedded']['item']);
        self::assertSame('Sub-01', $page1['_embedded']['item'][0]['name']);
        self::assertSame('Sub-25', $page1['_embedded']['item'][24]['name']);
        self::assertSame(['href' => self::URL.'?page=1'], $page1['_links']['self']);
        self::assertSame(['href' => self::URL.'?page=1'], $page1['_links']['first']);
        self::assertSame(['href' => self::URL.'?page=2'], $page1['_links']['last']);
        self::assertSame(['href' => self::URL.'?page=2'], $page1['_links']['next']);

        $page2 = $this->requestAs($this->user, 'GET', self::URL.'?page=2')->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame(['Sub-26', 'Sub-27'], array_column($page2['_embedded']['item'], 'name'));
        self::assertSame(['href' => self::URL.'?page=1'], $page2['_links']['prev']);

        // Past the last page is an empty, still successful, collection.
        $page3 = $this->requestAs($this->user, 'GET', self::URL.'?page=3');
        self::assertResponseIsSuccessful();
        self::assertSame(27, $page3->toArray()['totalItems']);
        self::assertArrayNotHasKey('_embedded', $page3->toArray());
    }

    public function testCollectionFiltersByCategoryUuid(): void
    {
        $video = $this->categories->create($this->user, 'Video', '#111111');
        $music = $this->categories->create($this->user, 'Music', '#222222');
        $this->makeSubscription($this->user, $video, 'AlphaFlix', BillingCycle::Monthly, '2026-03-05', 10.0);
        $this->makeSubscription($this->user, $video, 'BetaFlix', BillingCycle::Monthly, '2026-03-06', 20.0);
        $this->makeSubscription($this->user, $music, 'GammaTunes', BillingCycle::Monthly, '2026-03-07', 30.0);

        $data = $this->requestAs($this->user, 'GET', self::URL.'?category='.$video->getId())->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame(['AlphaFlix', 'BetaFlix'], array_column($data['_embedded']['item'], 'name'));
    }

    public function testCollectionWithUnknownOrForeignCategoryReturnsEmptyWithoutDisclosure(): void
    {
        $own = $this->categories->create($this->user, 'Video', '#111111');
        $this->makeSubscription($this->user, $own, 'OwnFlix', BillingCycle::Monthly, '2026-03-05', 10.0);
        $foreignCategory = $this->categories->create($this->other, 'Foreign', '#222222');
        $this->makeSubscription($this->other, $foreignCategory, 'StrangerFlix', BillingCycle::Monthly, '2026-03-05', 10.0);

        foreach ([(string) Uuid::v4(), (string) $foreignCategory->getId()] as $filter) {
            $response = $this->requestAs($this->user, 'GET', self::URL.'?category='.$filter);

            self::assertResponseIsSuccessful();
            self::assertJsonContains(['totalItems' => 0]);
            self::assertStringNotContainsStringIgnoringCase('StrangerFlix', $response->getContent());
        }
    }

    public function testCollectionWithMalformedCategoryIs422(): void
    {
        $this->requestAs($this->user, 'GET', self::URL.'?category=not-a-uuid');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['category'], $this->violatedPaths());
    }

    public function testCollectionSortsByNameInBothDirections(): void
    {
        $category = $this->categories->create($this->user, 'Video', '#111111');
        foreach (['Charlie', 'Alpha', 'Bravo'] as $name) {
            $this->makeSubscription($this->user, $category, $name, BillingCycle::Monthly, '2026-03-05', 10.0);
        }

        $asc = $this->requestAs($this->user, 'GET', self::URL.'?order[name]=asc')->toArray();
        self::assertSame(['Alpha', 'Bravo', 'Charlie'], array_column($asc['_embedded']['item'], 'name'));

        $desc = $this->requestAs($this->user, 'GET', self::URL.'?order[name]=desc')->toArray();
        self::assertSame(['Charlie', 'Bravo', 'Alpha'], array_column($desc['_embedded']['item'], 'name'));
    }

    public function testCollectionSortsByComparablePriceUsingConvertedAmounts(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->categories->create($this->user, 'Video', '#111111');
        // EUR 10.00 converted by hand to USD 11.00 must compare as 11 USD.
        $this->makeSubscription($this->user, $category, 'Cross Mid', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');
        $this->makeSubscription($this->user, $category, 'Same Pricey', BillingCycle::Monthly, '2026-03-05', 12.0, 'USD');
        // USD 120.00 yearly normalizes to USD 10.00 monthly.
        $this->makeSubscription($this->user, $category, 'Yearly Cheap', BillingCycle::Yearly, '2026-03-05', 120.0, 'USD');

        $asc = $this->requestAs($this->user, 'GET', self::URL.'?order[price]=asc')->toArray();
        self::assertSame(['Yearly Cheap', 'Cross Mid', 'Same Pricey'], array_column($asc['_embedded']['item'], 'name'));

        $desc = $this->requestAs($this->user, 'GET', self::URL.'?order[price]=desc')->toArray();
        self::assertSame(['Same Pricey', 'Cross Mid', 'Yearly Cheap'], array_column($desc['_embedded']['item'], 'name'));
    }

    public function testCollectionPriceTiesBreakStablyByNameThenId(): void
    {
        $category = $this->categories->create($this->user, 'Video', '#111111');
        // Same comparable price (12.00 monthly vs 144.00 yearly): name order
        // wins in both directions so the tie never flips with the direction.
        $this->makeSubscription($this->user, $category, 'Tie Beta', BillingCycle::Monthly, '2026-03-05', 12.0);
        $this->makeSubscription($this->user, $category, 'Tie Alpha', BillingCycle::Yearly, '2026-03-05', 144.0);

        $asc = $this->requestAs($this->user, 'GET', self::URL.'?order[price]=asc')->toArray();
        self::assertSame(['Tie Alpha', 'Tie Beta'], array_column($asc['_embedded']['item'], 'name'));

        $desc = $this->requestAs($this->user, 'GET', self::URL.'?order[price]=desc')->toArray();
        self::assertSame(['Tie Alpha', 'Tie Beta'], array_column($desc['_embedded']['item'], 'name'));
    }

    public function testCollectionSortsByNextRenewalInBothDirections(): void
    {
        $category = $this->categories->create($this->user, 'Video', '#111111');
        $this->makeSubscription($this->user, $category, 'Far Renewal', BillingCycle::Monthly, (new \DateTimeImmutable('today +20 days'))->format('Y-m-d'), 10.0);
        $this->makeSubscription($this->user, $category, 'Soon Renewal', BillingCycle::Monthly, (new \DateTimeImmutable('today +5 days'))->format('Y-m-d'), 10.0);
        $this->makeSubscription($this->user, $category, 'Mid Renewal', BillingCycle::Monthly, (new \DateTimeImmutable('today +10 days'))->format('Y-m-d'), 10.0);

        $asc = $this->requestAs($this->user, 'GET', self::URL.'?order[renewal]=asc')->toArray();
        self::assertSame(['Soon Renewal', 'Mid Renewal', 'Far Renewal'], array_column($asc['_embedded']['item'], 'name'));

        $desc = $this->requestAs($this->user, 'GET', self::URL.'?order[renewal]=desc')->toArray();
        self::assertSame(['Far Renewal', 'Mid Renewal', 'Soon Renewal'], array_column($desc['_embedded']['item'], 'name'));
    }

    public function testCollectionSortsBeforePaginating(): void
    {
        $category = $this->categories->create($this->user, 'Video', '#111111');
        for ($i = 1; $i <= 27; ++$i) {
            $this->makeSubscription($this->user, $category, sprintf('Item-%02d', $i), BillingCycle::Monthly, '2026-03-05', (float) $i);
        }

        // Price descending across the whole owned collection: page 1 holds the
        // 25 priciest, page 2 the 2 cheapest — pagination slices the sorted
        // collection, not the insertion order.
        $page1 = $this->requestAs($this->user, 'GET', self::URL.'?order[price]=desc&page=1')->toArray();
        self::assertSame('Item-27', $page1['_embedded']['item'][0]['name']);
        self::assertSame('Item-03', $page1['_embedded']['item'][24]['name']);

        $page2 = $this->requestAs($this->user, 'GET', self::URL.'?order[price]=desc&page=2')->toArray();
        self::assertSame(['Item-02', 'Item-01'], array_column($page2['_embedded']['item'], 'name'));
    }

    public function testCollectionWithInvalidSortIs422(): void
    {
        foreach ([
            'unknown field' => 'order[bogus]=asc',
            'unknown direction' => 'order[name]=sideways',
            'scalar order' => 'order=asc',
            'two fields' => 'order[name]=asc&order[price]=desc',
            'legacy keys' => 'sort=price&order=asc',
        ] as $label => $query) {
            $this->requestAs($this->user, 'GET', self::URL.'?'.$query);

            self::assertResponseStatusCodeSame(422, $label);
            self::assertSame(['order'], $this->violatedPaths(), $label);
        }
    }

    public function testCollectionDerivesPendingReviewFromConvertedCurrency(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->categories->create($this->user, 'Video', '#111111');
        // Fresh converted amount stamped with the current main currency.
        $this->makeSubscription($this->user, $category, 'Reviewed Cross', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');
        // Cross-currency without any converted amount: pending review.
        $this->makeSubscription($this->user, $category, 'Unreviewed Cross', BillingCycle::Monthly, '2026-03-05', 9.0, 'EUR');
        // Same-currency subscription: never pending review.
        $this->makeSubscription($this->user, $category, 'Same Currency', BillingCycle::Monthly, '2026-03-05', 8.0, 'USD');

        $data = $this->requestAs($this->user, 'GET', self::URL.'?order[name]=asc')->toArray();
        $byName = [];
        foreach ($data['_embedded']['item'] as $row) {
            $byName[$row['name']] = $row;
        }

        self::assertFalse($byName['Reviewed Cross']['pendingReview']);
        self::assertSame('USD', $byName['Reviewed Cross']['convertedCurrency']);
        self::assertTrue($byName['Unreviewed Cross']['pendingReview']);
        self::assertFalse($byName['Same Currency']['pendingReview']);
    }

    public function testCreateReturns201WithServerControlledFields(): void
    {
        $category = $this->categories->create($this->user, 'Video', '#111111');

        $response = $this->requestAs($this->user, 'POST', self::URL, ['json' => [
            'name' => 'StreamFlix',
            'billingCycle' => 'monthly',
            'amount' => '15.99',
            'nextPayment' => '2026-04-05',
            'currency' => 'USD',
            'notes' => 'Family plan',
            'category' => self::CATEGORIES.'/'.$category->getId(),
            // Server-controlled fields must be ignored, never accepted.
            'convertedCurrency' => 'PLN',
            'pendingReview' => true,
            'createdAt' => '2001-01-01T00:00:00+00:00',
            'updatedAt' => '2001-01-01T00:00:00+00:00',
        ]]);
        $data = $response->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('content-type', 'application/hal+json');
        self::assertResponseHeaderSame('location', self::URL.'/'.$data['id']);
        self::assertSame('StreamFlix', $data['name']);
        self::assertSame('monthly', $data['billingCycle']);
        self::assertSame('15.99', $data['amount']);
        self::assertSame('2026-04-05', $data['nextPayment']);
        self::assertSame('USD', $data['currency']);
        self::assertSame('Family plan', $data['notes']);
        self::assertSame(['href' => self::CATEGORIES.'/'.$category->getId()], $data['_links']['category']);
        self::assertArrayNotHasKey('convertedCurrency', $data);
        self::assertFalse($data['pendingReview'], 'A fresh same-currency record is not pending review.');
        self::assertNotSame('2001-01-01T00:00:00+00:00', $data['createdAt']);
        self::assertArrayHasKey('createdAt', $data);
        self::assertArrayHasKey('updatedAt', $data);
        self::assertArrayNotHasKey('owner', $data);

        // The record really belongs to the token User.
        $stored = $this->storedSubscription(Uuid::fromString($data['id']));
        self::assertSame('api-sub-user@example.com', $stored->getOwner()->getEmail());
        self::assertSame((string) $category->getId(), (string) $stored->getCategory()->getId());
    }

    public function testCreateIsScopedToTokenUser(): void
    {
        $category = $this->categories->create($this->user, 'Video', '#111111');

        $data = $this->requestAs($this->user, 'POST', self::URL, ['json' => array_merge($this->validPayload(), ['category' => self::CATEGORIES.'/'.$category->getId()])])->toArray();
        self::assertResponseStatusCodeSame(201);

        // The other User never sees it: the collection hides it, the detail 404s.
        $collection = $this->requestAs($this->other, 'GET', self::URL)->toArray();
        self::assertSame(0, $collection['totalItems']);

        $this->requestAs($this->other, 'GET', self::URL.'/'.$data['id']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCreateWithForeignUnknownOrMalformedCategoryIriIs422WithoutDisclosure(): void
    {
        $foreignCategory = $this->categories->create($this->other, 'Foreign', '#222222');
        $missing = (string) Uuid::v4();

        foreach ([
            self::CATEGORIES.'/'.$missing,
            self::CATEGORIES.'/'.$foreignCategory->getId(),
            self::CATEGORIES.'/not-a-uuid',
        ] as $badCategory) {
            $response = $this->requestAs($this->user, 'POST', self::URL, ['json' => array_merge($this->validPayload(), ['category' => $badCategory])]);

            self::assertSame(422, $response->getStatusCode(), sprintf('category %s must be rejected', $badCategory));
            self::assertSame(['category'], $this->violatedPaths());
            self::assertStringNotContainsStringIgnoringCase('Foreign', $response->getContent(false));
        }
    }

    public function testCreateWithoutCategoryPicksExistingDefaultFirst(): void
    {
        $video = $this->categories->create($this->user, 'Video', '#111111');
        $default = $this->categories->create($this->user, ExpenseCategory::DEFAULT_NAME, '#222222');

        $data = $this->requestAs($this->user, 'POST', self::URL, ['json' => $this->validPayload()])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['href' => self::CATEGORIES.'/'.$default->getId()], $data['_links']['category']);
        self::assertNotSame((string) $video->getId(), (string) $this->storedSubscription(Uuid::fromString($data['id']))->getCategory()->getId());
    }

    public function testCreateWithoutCategoryCreatesDefaultForFreshUser(): void
    {
        $data = $this->requestAs($this->user, 'POST', self::URL, ['json' => $this->validPayload()])->toArray();

        self::assertResponseStatusCodeSame(201);

        // The existing default-category selection rule applies: a fresh User
        // gets the `Subscriptions` default.
        $this->em->clear();
        $default = $this->em->getRepository(ExpenseCategory::class)->findOneBy(['name' => ExpenseCategory::DEFAULT_NAME]);
        self::assertNotNull($default);
        self::assertSame(['href' => self::CATEGORIES.'/'.$default->getId()], $data['_links']['category']);
    }

    public function testCreateWithoutCurrencyDefaultsToMainCurrency(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $data = $this->requestAs($this->user, 'POST', self::URL, ['json' => [
            'name' => 'Defaulted Currency',
            'billingCycle' => 'monthly',
            'amount' => '10.00',
            'nextPayment' => '2026-04-05',
        ]])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame('USD', $data['currency']);
        self::assertArrayNotHasKey('convertedAmount', $data);
        self::assertFalse($data['pendingReview']);
    }

    public function testCreateWithoutCurrenciesPreservesLegacyBehavior(): void
    {
        // No confirmed main currency: legacy data stays untouched.
        $data = $this->requestAs($this->user, 'POST', self::URL, ['json' => [
            'name' => 'Legacy Sub',
            'billingCycle' => 'monthly',
            'amount' => '10.00',
            'nextPayment' => '2026-04-05',
        ]])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertArrayNotHasKey('currency', $data);
        self::assertArrayNotHasKey('convertedAmount', $data);
        self::assertArrayNotHasKey('convertedCurrency', $data);
        self::assertFalse($data['pendingReview']);
    }

    public function testCreateCrossCurrencyWithoutConvertedAmountIs422(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $this->requestAs($this->user, 'POST', self::URL, ['json' => array_merge($this->validPayload(), ['currency' => 'EUR'])]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['convertedAmount'], $this->violatedPaths());
    }

    public function testCreateCrossCurrencyWithConvertedAmountStampsMainCurrency(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $data = $this->requestAs($this->user, 'POST', self::URL, ['json' => array_merge($this->validPayload(), [
            'currency' => 'EUR',
            'convertedAmount' => '11.00',
        ])])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame('EUR', $data['currency']);
        self::assertSame('11.00', $data['convertedAmount']);
        self::assertSame('USD', $data['convertedCurrency']);
        self::assertFalse($data['pendingReview']);
    }

    public function testCreateSameCurrencyWithConvertedAmountIs422(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $this->requestAs($this->user, 'POST', self::URL, ['json' => array_merge($this->validPayload(), [
            'currency' => 'USD',
            'convertedAmount' => '10.00',
        ])]);


        self::assertResponseStatusCodeSame(422);
        self::assertSame(['convertedAmount'], $this->violatedPaths());
    }

    public function testCreateWithInvalidFieldsReturns422WithFieldViolations(): void
    {
        // Baseline valid payload; each case breaks exactly one field.
        $cases = [
            'name' => ['name' => 'AB'],
            'billingCycle' => ['billingCycle' => 'weekly'],
            'amount' => ['amount' => '-3.5'],
            'nextPayment' => ['nextPayment' => '2026-04-05T10:00:00+00:00'],
            'currency' => ['currency' => 'XX1'],
            'notes' => ['notes' => str_repeat('n', 2001)],
        ];

        foreach ($cases as $field => $override) {
            $this->requestAs($this->user, 'POST', self::URL, ['json' => array_merge($this->validPayload(), $override)]);

            self::assertResponseStatusCodeSame(422, sprintf('field %s must be rejected', $field));
            self::assertSame([$field], $this->violatedPaths(), sprintf('violation for %s expected', $field));
        }
    }

    public function testCreateWithMissingRequiredFieldsReturns422(): void
    {
        $this->requestAs($this->user, 'POST', self::URL, ['json' => new \stdClass()]);

        self::assertResponseStatusCodeSame(422);
        // Category is not required: an omitted category applies the
        // default-category rule instead.
        self::assertSame(['amount', 'billingCycle', 'name', 'nextPayment'], $this->violatedPaths());
    }

    public function testCreateWithIdInBodyIsBadRequest(): void
    {
        $this->requestAs($this->user, 'POST', self::URL, ['json' => array_merge($this->validPayload(), ['id' => (string) Uuid::v4()])]);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testCreateBeyondLimitReturns409(): void
    {
        $this->user->setSubscriptionsLimit(1);
        $this->em->persist($this->user);
        $this->em->flush();
        $category = $this->categories->create($this->user, 'Video', '#111111');
        $this->makeSubscription($this->user, $category, 'First', BillingCycle::Monthly, '2026-03-05', 10.0);

        $response = $this->requestAs($this->user, 'POST', self::URL, ['json' => array_merge($this->validPayload(), [
            'name' => 'Second',
            'category' => self::CATEGORIES.'/'.$category->getId(),
        ])]);


        self::assertSame(409, $response->getStatusCode());
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertJsonContains([
            'type' => '/problems/subscription-limit-reached',
            'title' => 'Subscription limit reached',
            'status' => 409,
            'detail' => 'You have reached the maximum number of subscriptions.',
        ]);
    }

    public function testCreateLimitIsPerUser(): void
    {
        $this->user->setSubscriptionsLimit(1);
        $this->em->persist($this->user);
        $this->em->flush();
        $category = $this->categories->create($this->user, 'Video', '#111111');
        $this->makeSubscription($this->user, $category, 'First', BillingCycle::Monthly, '2026-03-05', 10.0);

        // The other User is unaffected by this User's full limit. The limit
        // gate serializes concurrent creates on the owner row, so a raced
        // request takes the same 409 path instead of slipping a second row
        // past the check.
        $this->requestAs($this->other, 'POST', self::URL, ['json' => array_merge($this->validPayload(), ['name' => 'Other Sub'])]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testCreateInvalidDataAtLimitReturns422(): void
    {
        $this->user->setSubscriptionsLimit(1);
        $this->em->persist($this->user);
        $this->em->flush();
        $category = $this->categories->create($this->user, 'Video', '#111111');
        $this->makeSubscription($this->user, $category, 'First', BillingCycle::Monthly, '2026-03-05', 10.0);

        // Invalid data stays 422 even when the limit is also reached.
        $this->requestAs($this->user, 'POST', self::URL, ['json' => array_merge($this->validPayload(), ['name' => 'AB'])]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testPatchNameOnlyPreservesOtherFields(): void
    {
        $category = $this->categories->create($this->user, 'Video', '#111111');
        $subscription = $this->makeSubscription($this->user, $category, 'Old Name', BillingCycle::Monthly, '2026-03-05', 10.0, 'USD', null, null, 'Keep me');

        $data = $this->patch($subscription, ['name' => 'New Name'])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('New Name', $data['name']);
        self::assertSame('monthly', $data['billingCycle']);
        self::assertSame('10.00', $data['amount']);
        self::assertSame('2026-03-05', $data['nextPayment']);
        self::assertSame('USD', $data['currency']);
        self::assertSame('Keep me', $data['notes']);
        self::assertSame(['href' => self::CATEGORIES.'/'.$category->getId()], $data['_links']['category']);
        self::assertSame((string) $subscription->getId(), $data['id']);
    }

    public function testPatchWithEmptyObjectOrOnlyIgnoredFieldsIs422(): void
    {
        $category = $this->categories->create($this->user, 'Video', '#111111');
        $subscription = $this->makeSubscription($this->user, $category, 'Old Name', BillingCycle::Monthly, '2026-03-05', 10.0);

        foreach (['empty object' => new \stdClass(), 'read-only members' => ['convertedCurrency' => 'PLN', 'pendingReview' => true]] as $label => $body) {
            $this->patch($subscription, $body);

            self::assertResponseStatusCodeSame(422, $label);
        }

        self::assertSame('Old Name', $this->storedSubscription($subscription->getId())->getName());
    }

    public function testPatchRequiredFieldsCannotBeCleared(): void
    {
        $category = $this->categories->create($this->user, 'Video', '#111111');
        $subscription = $this->makeSubscription($this->user, $category, 'Old Name', BillingCycle::Monthly, '2026-03-05', 10.0, 'USD');

        foreach (['name', 'billingCycle', 'amount', 'nextPayment', 'category'] as $field) {
            $this->patch($subscription, [$field => null]);

            self::assertResponseStatusCodeSame(422, sprintf('field %s must not be clearable', $field));
            self::assertContains($field, $this->violatedPaths(), sprintf('violation for %s expected', $field));
        }

        $stored = $this->storedSubscription($subscription->getId());
        self::assertSame('Old Name', $stored->getName());
        self::assertSame(BillingCycle::Monthly, $stored->getBillingCycle());
        self::assertSame(10.0, $stored->getAmount());
        self::assertSame('2026-03-05', $stored->getNextPayment()->format('Y-m-d'));
    }

    public function testPatchIgnoresServerControlledFields(): void
    {
        $category = $this->categories->create($this->user, 'Video', '#111111');
        $subscription = $this->makeSubscription($this->user, $category, 'Old Name', BillingCycle::Monthly, '2026-03-05', 10.0);
        $id = (string) $subscription->getId();

        $data = $this->patch($subscription, [
            'name' => 'New Name',
            'id' => (string) Uuid::v4(),
            'convertedCurrency' => 'PLN',
            'pendingReview' => true,
            'createdAt' => '2001-01-01T00:00:00+00:00',
            'updatedAt' => '2001-01-01T00:00:00+00:00',
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('New Name', $data['name']);
        self::assertSame($id, $data['id']);
        self::assertArrayNotHasKey('owner', $data);

        $stored = $this->storedSubscription($subscription->getId());
        self::assertSame('api-sub-user@example.com', $stored->getOwner()->getEmail());
    }

    public function testPatchNotesCanBeCleared(): void
    {
        $category = $this->categories->create($this->user, 'Video', '#111111');
        $subscription = $this->makeSubscription($this->user, $category, 'Noted', BillingCycle::Monthly, '2026-03-05', 10.0, 'USD', null, null, 'Some notes');

        $data = $this->patch($subscription, ['notes' => null])->toArray();

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('notes', $data);
        self::assertNull($this->storedSubscription($subscription->getId())->getNotes());
    }

    public function testPatchConvertedAmountCanBeClearedWhenResultStaysValid(): void
    {
        // No confirmed main currency: legacy converted input carries no
        // requirement, so clearing it stays valid.
        $category = $this->categories->create($this->user, 'Video', '#111111');
        $subscription = $this->makeSubscription($this->user, $category, 'Legacy', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');

        $data = $this->patch($subscription, ['convertedAmount' => null])->toArray();

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('convertedAmount', $data);
        self::assertArrayNotHasKey('convertedCurrency', $data);
    }

    public function testPatchConvertedAmountClearWhenCrossCurrencyIs422AndLeavesRecordUnchanged(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->categories->create($this->user, 'Video', '#111111');
        $subscription = $this->makeSubscription($this->user, $category, 'Cross', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');

        $this->patch($subscription, ['convertedAmount' => null]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['convertedAmount'], $this->violatedPaths());

        $stored = $this->storedSubscription($subscription->getId());
        self::assertSame(11.0, $stored->getConvertedAmount());
        self::assertSame('USD', $stored->getConvertedCurrency());
    }

    public function testPatchInvalidValuesAre422AndLeaveRecordUnchanged(): void
    {
        $category = $this->categories->create($this->user, 'Video', '#111111');
        $subscription = $this->makeSubscription($this->user, $category, 'Old Name', BillingCycle::Monthly, '2026-03-05', 10.0, 'USD', null, null, 'Keep me');

        $this->patch($subscription, [
            'name' => 'AB',
            'amount' => '-3.5',
            'nextPayment' => '2026-04-05T10:00:00+00:00',
            'currency' => 'XX1',
            'notes' => str_repeat('n', 2001),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['amount', 'currency', 'name', 'nextPayment', 'notes'], $this->violatedPaths());

        // Atomic: nothing was applied, not even the valid-adjacent notes.
        $stored = $this->storedSubscription($subscription->getId());
        self::assertSame('Old Name', $stored->getName());
        self::assertSame('Keep me', $stored->getNotes());
        self::assertSame(10.0, $stored->getAmount());
    }

    public function testUnrelatedUpdatePreservesPendingReview(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->categories->create($this->user, 'Video', '#111111');
        $subscription = $this->makeSubscription($this->user, $category, 'Stale', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');

        // The main currency moves on: the stored figure is now stale.
        $this->user->setMainCurrency('PLN');
        $this->em->persist($this->user);
        $this->em->flush();
        $this->em->clear();

        $data = $this->patch($subscription, ['notes' => 'Unrelated edit'])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('Unrelated edit', $data['notes']);
        self::assertTrue($data['pendingReview']);
        self::assertSame('11.00', $data['convertedAmount']);
        self::assertSame('USD', $data['convertedCurrency']);
    }

    public function testCurrencyChangeDropsOldConvertedFigure(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->categories->create($this->user, 'Video', '#111111');
        $subscription = $this->makeSubscription($this->user, $category, 'Cross', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');

        // A figure computed for EUR must never be reused for GBP: changing the
        // currency while supplying the replacement reviews the new figure.
        $data = $this->patch($subscription, ['currency' => 'GBP', 'convertedAmount' => '12.00'])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('GBP', $data['currency']);
        self::assertSame('12.00', $data['convertedAmount']);
        self::assertSame('USD', $data['convertedCurrency']);
        self::assertFalse($data['pendingReview']);
    }

    public function testCurrencyChangeWithoutReplacementConvertedAmountIs422(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->categories->create($this->user, 'Video', '#111111');
        $subscription = $this->makeSubscription($this->user, $category, 'Cross', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');

        $this->patch($subscription, ['currency' => 'GBP']);

        self::assertResponseStatusCodeSame(422);

        $stored = $this->storedSubscription($subscription->getId());
        self::assertSame('EUR', $stored->getCurrency());
        self::assertSame(11.0, $stored->getConvertedAmount());
        self::assertSame('USD', $stored->getConvertedCurrency());
    }

    public function testConfirmConvertedForReviewsEvenUnchangedValue(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->categories->create($this->user, 'Video', '#111111');
        $subscription = $this->makeSubscription($this->user, $category, 'Stale', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');

        $this->user->setMainCurrency('PLN');
        $this->em->persist($this->user);
        $this->em->flush();
        $this->em->clear();

        // The numeric value is unchanged, yet the explicit confirmation
        // reviews it against the current main currency. The write-only member
        // never appears on reads.
        $data = $this->patch($subscription, ['convertedAmount' => '11.00', 'confirmConvertedFor' => 'PLN'])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('11.00', $data['convertedAmount']);
        self::assertSame('PLN', $data['convertedCurrency']);
        self::assertFalse($data['pendingReview']);
        self::assertArrayNotHasKey('confirmConvertedFor', $data);

        self::assertSame('PLN', $this->storedSubscription($subscription->getId())->getConvertedCurrency());
    }

    public function testConfirmConvertedForMismatchIs409AndLeavesRecordUnchanged(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->categories->create($this->user, 'Video', '#111111');
        $subscription = $this->makeSubscription($this->user, $category, 'Stale', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');

        $this->user->setMainCurrency('PLN');
        $this->em->persist($this->user);
        $this->em->flush();
        $this->em->clear();

        $response = $this->patch($subscription, [
            'name' => 'Should Not Apply',
            'convertedAmount' => '11.00',
            'confirmConvertedFor' => 'USD',
        ]);

        self::assertSame(409, $response->getStatusCode());
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertJsonContains([
            'type' => '/problems/currency-conflict',
            'title' => 'Converted amount currency conflict',
            'status' => 409,
        ]);

        // Atomic: the conflict applied nothing, not even the unrelated name.
        $stored = $this->storedSubscription($subscription->getId());
        self::assertSame('Stale', $stored->getName());
        self::assertSame('USD', $stored->getConvertedCurrency());
        self::assertSame(11.0, $stored->getConvertedAmount());
    }

    public function testConfirmConvertedForWithoutConvertedAmountIs422(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->categories->create($this->user, 'Video', '#111111');
        $subscription = $this->makeSubscription($this->user, $category, 'Cross', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');

        $this->patch($subscription, ['confirmConvertedFor' => 'USD']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['confirmConvertedFor'], $this->violatedPaths());
    }

    public function testChangedConvertedAmountWithoutConfirmStampsCurrentMainCurrency(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->categories->create($this->user, 'Video', '#111111');
        $subscription = $this->makeSubscription($this->user, $category, 'Cross', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');

        $data = $this->patch($subscription, ['convertedAmount' => '12.50'])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('12.50', $data['convertedAmount']);
        self::assertSame('USD', $data['convertedCurrency']);
        self::assertFalse($data['pendingReview']);
    }

    public function testPatchCategoryChangeRequiresOwnership(): void
    {
        $own = $this->categories->create($this->user, 'Video', '#111111');
        $target = $this->categories->create($this->user, 'Music', '#222222');
        $foreign = $this->categories->create($this->other, 'Foreign', '#333333');
        $subscription = $this->makeSubscription($this->user, $own, 'Movable', BillingCycle::Monthly, '2026-03-05', 10.0);

        $data = $this->patch($subscription, ['category' => self::CATEGORIES.'/'.$target->getId()])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame(['href' => self::CATEGORIES.'/'.$target->getId()], $data['_links']['category']);

        $missing = (string) Uuid::v4();
        foreach ([$missing, (string) $foreign->getId(), 'not-a-uuid'] as $badId) {
            $badCategory = self::CATEGORIES.'/'.$badId;
            $response = $this->patch($subscription, ['category' => $badCategory]);

            self::assertSame(422, $response->getStatusCode(), sprintf('category %s must be rejected', $badCategory));
            self::assertSame(['category'], $this->violatedPaths());
            self::assertStringNotContainsStringIgnoringCase('Foreign', $response->getContent(false));
        }

        // The failed attempts left the owned category in place.
        self::assertSame((string) $target->getId(), (string) $this->storedSubscription($subscription->getId())->getCategory()->getId());
    }

    public function testPatchForeignMissingAndMalformedIdsReturn404(): void
    {
        $foreignCategory = $this->categories->create($this->other, 'Foreign', '#222222');
        $foreign = $this->makeSubscription($this->other, $foreignCategory, 'ForeignFlix', BillingCycle::Monthly, '2026-03-05', 10.0);

        $foreignResponse = $this->requestAs($this->user, 'PATCH', self::URL.'/'.$foreign->getId(), [
            'json' => ['name' => 'Intrusion'],
            'headers' => self::MERGE_PATCH,
        ]);
        self::assertSame(404, $foreignResponse->getStatusCode());
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertStringNotContainsStringIgnoringCase('ForeignFlix', $foreignResponse->getContent(false));

        $this->requestAs($this->user, 'PATCH', self::URL.'/'.Uuid::v4(), ['json' => ['name' => 'Ghost'], 'headers' => self::MERGE_PATCH]);
        self::assertResponseStatusCodeSame(404);

        $this->requestAs($this->user, 'PATCH', self::URL.'/not-a-uuid', ['json' => ['name' => 'Ghost'], 'headers' => self::MERGE_PATCH]);
        self::assertResponseStatusCodeSame(404);

        // The foreign record is untouched.
        $this->em->clear();
        self::assertSame('ForeignFlix', $this->em->getRepository(Subscription::class)->find($foreign->getId())->getName());
    }

    public function testPatchRequiresMergePatchContentType(): void
    {
        $category = $this->categories->create($this->user, 'Video', '#111111');
        $subscription = $this->makeSubscription($this->user, $category, 'Old Name', BillingCycle::Monthly, '2026-03-05', 10.0);

        $this->requestAs($this->user, 'PATCH', self::URL.'/'.$subscription->getId(), ['json' => ['name' => 'New Name']]);

        self::assertResponseStatusCodeSame(415);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testDeleteOwnedReturns204AndRemovesRecord(): void
    {
        $category = $this->categories->create($this->user, 'Video', '#111111');
        $subscription = $this->makeSubscription($this->user, $category, 'Deletable', BillingCycle::Monthly, '2026-03-05', 10.0);
        $id = (string) $subscription->getId();

        $response = $this->requestAs($this->user, 'DELETE', self::URL.'/'.$id);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $response->getContent());
        self::assertSame(0, $this->countSubscriptions($this->user));

        $this->requestAs($this->user, 'GET', self::URL.'/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteForeignMissingMalformedAndRepeatedIdsReturn404(): void
    {
        $foreignCategory = $this->categories->create($this->other, 'Foreign', '#222222');
        $foreign = $this->makeSubscription($this->other, $foreignCategory, 'ForeignFlix', BillingCycle::Monthly, '2026-03-05', 10.0);

        $category = $this->categories->create($this->user, 'Video', '#111111');
        $temporary = $this->makeSubscription($this->user, $category, 'Temporary', BillingCycle::Monthly, '2026-03-05', 10.0);
        $temporaryId = (string) $temporary->getId();

        $foreignResponse = $this->requestAs($this->user, 'DELETE', self::URL.'/'.$foreign->getId());
        self::assertSame(404, $foreignResponse->getStatusCode());
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertJsonContains(['type' => '/errors/404']);
        self::assertStringNotContainsStringIgnoringCase('ForeignFlix', $foreignResponse->getContent(false));

        // The foreign record is untouched.
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Subscription::class)->find($foreign->getId()));

        $this->requestAs($this->user, 'DELETE', self::URL.'/'.Uuid::v4());
        self::assertResponseStatusCodeSame(404);

        $this->requestAs($this->user, 'DELETE', self::URL.'/not-a-uuid');
        self::assertResponseStatusCodeSame(404);

        // Deleting twice: the second DELETE 404s.
        $this->requestAs($this->user, 'DELETE', self::URL.'/'.$temporaryId);
        self::assertResponseStatusCodeSame(204);
        $this->requestAs($this->user, 'DELETE', self::URL.'/'.$temporaryId);
        self::assertResponseStatusCodeSame(404);
    }

    public function testErrorsAreProblemJsonWhateverTheAccept(): void
    {
        foreach (['application/json', 'application/ld+json', 'application/hal+json'] as $accept) {
            $this->requestAs($this->user, 'GET', self::URL.'/'.Uuid::v4(), ['headers' => ['accept' => $accept]]);

            self::assertResponseStatusCodeSame(404, $accept);
            self::assertResponseHeaderSame('content-type', 'application/problem+json', $accept);
        }
    }

    public function testCollectionNeverShowsForeignRows(): void
    {
        $foreignCategory = $this->categories->create($this->other, 'Secret Stash', '#333333');
        $this->makeSubscription($this->other, $foreignCategory, 'SecretFlix', BillingCycle::Monthly, '2026-03-05', 10.0);

        $response = $this->requestAs($this->user, 'GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['totalItems' => 0]);
        self::assertStringNotContainsString('SecretFlix', $response->getContent());
    }

    /**
     * @return array<string, string> a baseline valid create payload; callers
     *                              add or override single members per case
     */
    private function validPayload(): array
    {
        return [
            'name' => 'Valid Name',
            'billingCycle' => 'monthly',
            'amount' => '10.00',
            'nextPayment' => '2026-04-05',
            'currency' => 'USD',
        ];
    }

    private function patch(Subscription $subscription, mixed $body): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return $this->requestAs($this->user, 'PATCH', self::URL.'/'.$subscription->getId(), [
            'json' => $body,
            'headers' => self::MERGE_PATCH,
        ]);
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

    private function storedSubscription(Uuid $id): Subscription
    {
        $this->em->clear();
        $stored = $this->em->getRepository(Subscription::class)->find($id);
        self::assertNotNull($stored);

        return $stored;
    }

    private function countSubscriptions(User $owner): int
    {
        $this->em->clear();

        return $this->em->getRepository(Subscription::class)->count(['owner' => $owner->getId()]);
    }

    private function makeSubscription(
        User $owner,
        ExpenseCategory $category,
        string $name,
        BillingCycle $billingCycle,
        string $nextPayment,
        float $amount,
        ?string $currency = null,
        ?float $convertedAmount = null,
        ?string $convertedCurrency = null,
        ?string $notes = null,
    ): Subscription {
        $subscription = (new Subscription())
            ->setName($name)
            ->setBillingCycle($billingCycle)
            ->setNextPayment(new \DateTime($nextPayment))
            ->setAmount($amount)
            ->setCurrency($currency)
            ->setNotes($notes);
        $subscription->setConvertedAmount($convertedAmount);
        $subscription->setConvertedCurrency($convertedCurrency);
        $owner->addSubscription($subscription);
        $subscription->setCategory($category);

        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }
}
