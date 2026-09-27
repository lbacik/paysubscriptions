<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1;

use App\Entity\ExpenseCategory;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use App\OAuth2\ApiAccessTokenEntity;
use App\OAuth2\OAuth2Config;
use App\Service\ExpenseCategoryService;
use App\Tests\DatabaseTestCase;
use DateTimeImmutable;
use League\Bundle\OAuth2ServerBundle\Entity\Client as ClientEntity;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * List and read User-owned Subscriptions through protected API v1 (issue #96).
 *
 * Seam: public HTTP interface GET /api/v1/subscriptions (+ detail).
 * Behavior is observed through status, content-type, and body only.
 */
final class SubscriptionApiTest extends DatabaseTestCase
{
    private const CLIENT_ID = 'paysubs-cli';
    private const ISSUER = 'http://localhost';

    private User $user;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('api-sub-user@example.com', 'Fixture-Password-1', true);
        $this->other = $this->createUser('api-sub-other@example.com', 'Fixture-Password-1', true);
        $this->registerPublicClient();
    }

    public function testCollectionReturnsOwnedSubscriptionsAsJsonWithoutOwnerInternals(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        $this->makeSubscription($this->user, $category, 'Bravo', BillingCycle::Monthly, '2026-03-05', 20.0);
        $this->makeSubscription($this->user, $category, 'Alpha', BillingCycle::Monthly, '2026-03-06', 10.0);
        $foreignCategory = $this->createCategory($this->other, 'Foreign');
        $this->makeSubscription($this->other, $foreignCategory, 'ForeignFlix', BillingCycle::Monthly, '2026-03-05', 10.0);

        $this->client->request('GET', '/api/v1/subscriptions', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-sub-user@example.com'),
            'HTTP_Accept' => 'application/json',
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertCount(2, $data);

        // Default is name ascending.
        self::assertSame('Alpha', $data[0]['name']);
        self::assertSame('Bravo', $data[1]['name']);

        foreach ($data as $row) {
            foreach (['id', 'name', 'billingCycle', 'amount', 'nextPayment', 'currency', 'convertedAmount', 'convertedCurrency', 'pendingReview', 'categoryId', 'notes', 'createdAt', 'updatedAt'] as $field) {
                self::assertArrayHasKey($field, $row, sprintf('Expected field "%s" in subscription row', $field));
            }
            self::assertArrayNotHasKey('owner', $row);
        }

        // Never leaks the other User's subscription.
        self::assertNotContains('ForeignFlix', array_column($data, 'name'));
    }

    public function testDetailReturnsOwnedSubscriptionAsJson(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->createCategory($this->user, 'Video');
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

        $this->client->request('GET', '/api/v1/subscriptions/'.$subscription->getId(), [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-sub-user@example.com'),
            'HTTP_Accept' => 'application/json',
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame((string) $subscription->getId(), $data['id']);
        self::assertSame('CrossFlix', $data['name']);
        self::assertSame('monthly', $data['billingCycle']);
        self::assertEqualsWithDelta(10.0, (float) $data['amount'], 0.001);
        self::assertSame('2026-04-05', $data['nextPayment']);
        self::assertSame('EUR', $data['currency']);
        self::assertEqualsWithDelta(11.0, (float) $data['convertedAmount'], 0.001);
        self::assertSame('USD', $data['convertedCurrency']);
        self::assertFalse($data['pendingReview']);
        self::assertSame((string) $category->getId(), $data['categoryId']);
        self::assertSame('Family plan', $data['notes']);
        self::assertArrayHasKey('createdAt', $data);
        self::assertArrayHasKey('updatedAt', $data);
        self::assertArrayNotHasKey('owner', $data);
    }

    public function testDetailWithMissingIdReturnsProblemNotFound(): void
    {
        $missing = (string) \Symfony\Component\Uid\Uuid::v4();

        $this->client->request('GET', '/api/v1/subscriptions/'.$missing, [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-sub-user@example.com'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(404, $problem['status']);
        self::assertSame('subscription_not_found', $problem['code']);
        self::assertArrayHasKey('type', $problem);
        self::assertArrayHasKey('title', $problem);
        self::assertArrayHasKey('detail', $problem);
    }

    public function testDetailWithForeignIdReturnsProblemNotFoundWithoutDisclosure(): void
    {
        $foreignCategory = $this->createCategory($this->other, 'Foreign');
        $foreign = $this->makeSubscription($this->other, $foreignCategory, 'ForeignFlix', BillingCycle::Monthly, '2026-03-05', 10.0);

        $this->client->request('GET', '/api/v1/subscriptions/'.$foreign->getId(), [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-sub-user@example.com'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('subscription_not_found', $problem['code']);
        self::assertStringNotContainsStringIgnoringCase('ForeignFlix', (string) $this->client->getResponse()->getContent());
    }

    public function testDetailWithInvalidIdReturnsProblemNotFound(): void
    {
        $this->client->request('GET', '/api/v1/subscriptions/not-a-uuid', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-sub-user@example.com'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testCollectionPaginatesTwentyFivePerPageStartingAtPageOne(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        for ($i = 1; $i <= 27; ++$i) {
            $this->makeSubscription($this->user, $category, sprintf('Sub-%02d', $i), BillingCycle::Monthly, '2026-03-05', 10.0);
        }

        $token = $this->craftToken('api-sub-user@example.com');

        // Default (no page) is page 1.
        $this->client->request('GET', '/api/v1/subscriptions', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertResponseIsSuccessful();
        $page1 = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(25, $page1);
        self::assertSame('Sub-01', $page1[0]['name']);
        self::assertSame('Sub-25', $page1[24]['name']);

        $this->client->request('GET', '/api/v1/subscriptions?page=2', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertResponseIsSuccessful();
        $page2 = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(2, $page2);
        self::assertSame('Sub-26', $page2[0]['name']);
        self::assertSame('Sub-27', $page2[1]['name']);

        // Past the last page is an empty, still successful, collection.
        $this->client->request('GET', '/api/v1/subscriptions?page=3', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testCollectionFiltersByCategoryId(): void
    {
        $video = $this->createCategory($this->user, 'Video');
        $music = $this->createCategory($this->user, 'Music');
        $this->makeSubscription($this->user, $video, 'AlphaFlix', BillingCycle::Monthly, '2026-03-05', 10.0);
        $this->makeSubscription($this->user, $video, 'BetaFlix', BillingCycle::Monthly, '2026-03-06', 20.0);
        $this->makeSubscription($this->user, $music, 'GammaTunes', BillingCycle::Monthly, '2026-03-07', 30.0);

        $token = $this->craftToken('api-sub-user@example.com');

        $this->client->request('GET', '/api/v1/subscriptions?categoryId='.(string) $video->getId(), [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['AlphaFlix', 'BetaFlix'], array_column($data, 'name'));
    }

    public function testCollectionWithUnknownCategoryIdReturnsEmptyCollection(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        $this->makeSubscription($this->user, $category, 'AlphaFlix', BillingCycle::Monthly, '2026-03-05', 10.0);

        $this->client->request('GET', '/api/v1/subscriptions?categoryId='.(string) \Symfony\Component\Uid\Uuid::v4(), [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-sub-user@example.com'),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testCollectionWithForeignCategoryIdReturnsEmptyCollectionWithoutDisclosure(): void
    {
        $own = $this->createCategory($this->user, 'Video');
        $this->makeSubscription($this->user, $own, 'OwnFlix', BillingCycle::Monthly, '2026-03-05', 10.0);
        $foreignCategory = $this->createCategory($this->other, 'Foreign');
        $this->makeSubscription($this->other, $foreignCategory, 'StrangerFlix', BillingCycle::Monthly, '2026-03-05', 10.0);

        // Only the signed-in owner's subscriptions are ever loaded; a forged
        // foreign category id can therefore match nothing, never the
        // stranger's records.
        $this->client->request('GET', '/api/v1/subscriptions?categoryId='.(string) $foreignCategory->getId(), [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-sub-user@example.com'),
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([], $data);
        self::assertStringNotContainsStringIgnoringCase('StrangerFlix', (string) $this->client->getResponse()->getContent());
    }

    public function testCollectionSortsByNameInBothDirections(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        foreach (['Charlie', 'Alpha', 'Bravo'] as $name) {
            $this->makeSubscription($this->user, $category, $name, BillingCycle::Monthly, '2026-03-05', 10.0);
        }

        $token = $this->craftToken('api-sub-user@example.com');

        $this->client->request('GET', '/api/v1/subscriptions?sort=name&order=asc', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertSame(['Alpha', 'Bravo', 'Charlie'], $this->names());

        $this->client->request('GET', '/api/v1/subscriptions?sort=name&order=desc', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertSame(['Charlie', 'Bravo', 'Alpha'], $this->names());
    }

    public function testCollectionSortsByComparablePriceUsingConvertedAmounts(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->createCategory($this->user, 'Video');
        // EUR 10.00 converted by hand to USD 11.00 must compare as 11 USD.
        $this->makeSubscription($this->user, $category, 'Cross Mid', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');
        $this->makeSubscription($this->user, $category, 'Same Pricey', BillingCycle::Monthly, '2026-03-05', 12.0, 'USD');
        // USD 120.00 yearly normalizes to USD 10.00 monthly.
        $this->makeSubscription($this->user, $category, 'Yearly Cheap', BillingCycle::Yearly, '2026-03-05', 120.0, 'USD');

        $token = $this->craftToken('api-sub-user@example.com');

        $this->client->request('GET', '/api/v1/subscriptions?sort=price&order=asc', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertSame(['Yearly Cheap', 'Cross Mid', 'Same Pricey'], $this->names());

        $this->client->request('GET', '/api/v1/subscriptions?sort=price&order=desc', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertSame(['Same Pricey', 'Cross Mid', 'Yearly Cheap'], $this->names());
    }

    public function testCollectionPriceTiesBreakStablyByNameThenId(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        // Same comparable price (12.00 monthly vs 144.00 yearly): name order
        // wins in both directions so the tie never flips with the direction.
        $this->makeSubscription($this->user, $category, 'Tie Beta', BillingCycle::Monthly, '2026-03-05', 12.0);
        $this->makeSubscription($this->user, $category, 'Tie Alpha', BillingCycle::Yearly, '2026-03-05', 144.0);

        $token = $this->craftToken('api-sub-user@example.com');

        $this->client->request('GET', '/api/v1/subscriptions?sort=price&order=asc', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertSame(['Tie Alpha', 'Tie Beta'], $this->names());

        $this->client->request('GET', '/api/v1/subscriptions?sort=price&order=desc', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertSame(['Tie Alpha', 'Tie Beta'], $this->names());
    }

    public function testCollectionSortsByNextRenewalInBothDirections(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        $this->makeSubscription($this->user, $category, 'Far Renewal', BillingCycle::Monthly, (new \DateTimeImmutable('today +20 days'))->format('Y-m-d'), 10.0);
        $this->makeSubscription($this->user, $category, 'Soon Renewal', BillingCycle::Monthly, (new \DateTimeImmutable('today +5 days'))->format('Y-m-d'), 10.0);
        $this->makeSubscription($this->user, $category, 'Mid Renewal', BillingCycle::Monthly, (new \DateTimeImmutable('today +10 days'))->format('Y-m-d'), 10.0);

        $token = $this->craftToken('api-sub-user@example.com');

        $this->client->request('GET', '/api/v1/subscriptions?sort=renewal&order=asc', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertSame(['Soon Renewal', 'Mid Renewal', 'Far Renewal'], $this->names());

        $this->client->request('GET', '/api/v1/subscriptions?sort=renewal&order=desc', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertSame(['Far Renewal', 'Mid Renewal', 'Soon Renewal'], $this->names());
    }

    public function testCollectionRenewalTiesBreakStablyByName(): void
    {
        $anchor = (new \DateTimeImmutable('today +7 days'))->format('Y-m-d');
        $category = $this->createCategory($this->user, 'Video');
        $this->makeSubscription($this->user, $category, 'Tie Beta', BillingCycle::Monthly, $anchor, 12.0);
        $this->makeSubscription($this->user, $category, 'Tie Alpha', BillingCycle::Yearly, $anchor, 144.0);

        $token = $this->craftToken('api-sub-user@example.com');

        $this->client->request('GET', '/api/v1/subscriptions?sort=renewal&order=desc', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertSame(['Tie Alpha', 'Tie Beta'], $this->names());
    }

    public function testCollectionSortsBeforePaginating(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        for ($i = 1; $i <= 27; ++$i) {
            $this->makeSubscription($this->user, $category, sprintf('Item-%02d', $i), BillingCycle::Monthly, '2026-03-05', (float) $i);
        }

        $token = $this->craftToken('api-sub-user@example.com');

        // Price descending across the whole owned collection: page 1 holds the
        // 25 priciest, page 2 the 2 cheapest — pagination slices the sorted
        // collection, not the insertion order.
        $this->client->request('GET', '/api/v1/subscriptions?sort=price&order=desc&page=1', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        $page1 = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(25, $page1);
        self::assertSame('Item-27', $page1[0]['name']);
        self::assertSame('Item-03', $page1[24]['name']);

        $this->client->request('GET', '/api/v1/subscriptions?sort=price&order=desc&page=2', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        $page2 = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['Item-02', 'Item-01'], array_column($page2, 'name'));
    }

    public function testCollectionLegacySortKeysBehaveLikePrice(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        $this->makeSubscription($this->user, $category, 'Pricey', BillingCycle::Monthly, '2026-03-05', 50.0);
        $this->makeSubscription($this->user, $category, 'Cheap', BillingCycle::Monthly, '2026-03-05', 5.0);

        $token = $this->craftToken('api-sub-user@example.com');

        // Legacy keys predate the comparable-price sort; both monetary modes
        // follow the same price ordering through the shared normalizer.
        foreach (['monthly', 'yearly'] as $legacy) {
            $this->client->request('GET', '/api/v1/subscriptions?sort='.$legacy.'&order=asc', [], [], [
                'HTTP_Authorization' => 'Bearer '.$token,
            ]);
            self::assertResponseIsSuccessful();
            self::assertSame(['Cheap', 'Pricey'], $this->names(), sprintf('Legacy sort "%s" should order like price', $legacy));
        }
    }

    public function testCollectionFallsBackToDefaultsForInvalidNavigationParameters(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        $this->makeSubscription($this->user, $category, 'Bravo', BillingCycle::Monthly, '2026-03-05', 10.0);
        $this->makeSubscription($this->user, $category, 'Alpha', BillingCycle::Monthly, '2026-03-05', 10.0);

        $this->client->request('GET', '/api/v1/subscriptions?sort=bogus&order=sideways&page=0', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-sub-user@example.com'),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(['Alpha', 'Bravo'], $this->names());
    }

    public function testCollectionDerivesPendingReviewFromConvertedCurrency(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->createCategory($this->user, 'Video');
        // Fresh converted amount stamped with the current main currency.
        $this->makeSubscription($this->user, $category, 'Reviewed Cross', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');
        // Cross-currency without any converted amount: pending review.
        $this->makeSubscription($this->user, $category, 'Unreviewed Cross', BillingCycle::Monthly, '2026-03-05', 9.0, 'EUR');
        // Same-currency subscription: never pending review.
        $this->makeSubscription($this->user, $category, 'Same Currency', BillingCycle::Monthly, '2026-03-05', 8.0, 'USD');

        $this->client->request('GET', '/api/v1/subscriptions?sort=name&order=asc', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-sub-user@example.com'),
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $byName = [];
        foreach ($data as $row) {
            $byName[$row['name']] = $row;
        }

        self::assertFalse($byName['Reviewed Cross']['pendingReview']);
        self::assertSame('USD', $byName['Reviewed Cross']['convertedCurrency']);
        self::assertTrue($byName['Unreviewed Cross']['pendingReview']);
        self::assertFalse($byName['Same Currency']['pendingReview']);
    }

    public function testCollectionNeverReadsOrAltersWebSessionListState(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        $this->makeSubscription($this->user, $category, 'Alpha', BillingCycle::Monthly, '2026-03-05', 30.0);
        $this->makeSubscription($this->user, $category, 'Bravo', BillingCycle::Monthly, '2026-03-05', 10.0);
        $this->makeSubscription($this->user, $category, 'Charlie', BillingCycle::Monthly, '2026-03-05', 20.0);

        $token = $this->craftToken('api-sub-user@example.com');

        // Prime the web session list state: price descending.
        $this->login('api-sub-user@example.com', 'Fixture-Password-1');
        $this->client->request('GET', '/dashboard?sort=price&order=desc');
        self::assertResponseIsSuccessful();
        self::assertTableOrder($this->tableSection((string) $this->client->getResponse()->getContent()), ['Alpha', 'Charlie', 'Bravo']);

        // Explicit API navigation wins over the remembered web state.
        $this->client->request('GET', '/api/v1/subscriptions?sort=name&order=asc', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(['Alpha', 'Bravo', 'Charlie'], $this->names());

        // And a parameterless API call still uses the API defaults, not the
        // remembered web state.
        $this->client->request('GET', '/api/v1/subscriptions', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(['Alpha', 'Bravo', 'Charlie'], $this->names());

        // The web session state survives the API calls untouched.
        $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
        self::assertTableOrder($this->tableSection((string) $this->client->getResponse()->getContent()), ['Alpha', 'Charlie', 'Bravo']);
    }

    public function testCollectionWithoutTokenReturnsProblemUnauthorized(): void
    {
        $this->client->request('GET', '/api/v1/subscriptions');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(401, $problem['status']);
        self::assertArrayHasKey('code', $problem);
        self::assertArrayHasKey('type', $problem);
    }

    public function testDetailWithoutTokenReturnsProblemUnauthorized(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Alpha', BillingCycle::Monthly, '2026-03-05', 10.0);

        $this->client->request('GET', '/api/v1/subscriptions/'.$subscription->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testWebLoginStillWorksAfterApiChange(): void
    {
        // Session-based web login is preserved: the same User can sign in and
        // open the HTML dashboard while the stateless API stays separate.
        $this->login('api-sub-user@example.com', 'Fixture-Password-1');
        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/html', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testOpenApiDocumentCoversSubscriptionRoutesSchemasAndErrors(): void
    {
        $this->client->request('GET', '/api/v1/openapi.json', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-sub-user@example.com'),
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $doc = json_decode((string) $this->client->getResponse()->getContent(), true);

        // Routes.
        self::assertArrayHasKey('/subscriptions', $doc['paths']);
        self::assertArrayHasKey('/subscriptions/{id}', $doc['paths']);

        // Collection query parameters: explicit stateless navigation.
        $listParams = array_column($doc['paths']['/subscriptions']['get']['parameters'] ?? [], 'name');
        foreach (['categoryId', 'sort', 'order', 'page'] as $param) {
            self::assertContains($param, $listParams);
        }

        // Schema: UUID, editable data, category ID, convertedCurrency,
        // derived pendingReview, timestamps — and no owner internals.
        $subscription = $doc['components']['schemas']['Subscription'];
        foreach (['id', 'name', 'billingCycle', 'amount', 'nextPayment', 'currency', 'convertedAmount', 'convertedCurrency', 'pendingReview', 'categoryId', 'notes', 'createdAt', 'updatedAt'] as $field) {
            self::assertContains($field, $subscription['required']);
            self::assertArrayHasKey($field, $subscription['properties']);
        }
        self::assertArrayNotHasKey('owner', $subscription['properties']);

        // Statuses and problem errors.
        $listResponses = $doc['paths']['/subscriptions']['get']['responses'];
        foreach (['200', '401', '403'] as $status) {
            self::assertArrayHasKey($status, $listResponses);
        }

        $detailResponses = $doc['paths']['/subscriptions/{id}']['get']['responses'];
        foreach (['200', '401', '403', '404'] as $status) {
            self::assertArrayHasKey($status, $detailResponses);
        }

        $response = $doc['components']['responses']['SubscriptionNotFound'];
        self::assertArrayHasKey('application/problem+json', $response['content']);
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return array_column($data, 'name');
    }

    private function tableSection(string $content): string
    {
        $start = strpos($content, '<table id="subscriptions-table"');
        self::assertNotFalse($start, 'Subscriptions table not found on the dashboard');
        $end = strpos($content, '</table>', $start);
        self::assertNotFalse($end);

        return substr($content, $start, $end - $start);
    }

    private static function assertTableOrder(string $table, array $names): void
    {
        $positions = [];

        foreach ($names as $name) {
            $position = strpos($table, $name);
            self::assertNotFalse($position, sprintf('Expected "%s" in the subscription table', $name));
            $positions[] = $position;
        }

        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, sprintf(
            'Expected table order [%s]',
            implode(', ', $names),
        ));
    }

    private function createCategory(User $owner, string $name): ExpenseCategory
    {
        return static::getContainer()->get(ExpenseCategoryService::class)->create($owner, $name, '#577399');
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

    private function craftToken(
        ?string $userIdentifier,
        string $issuer = self::ISSUER,
        string $audience = OAuth2Config::API_AUDIENCE,
        ?DateTimeImmutable $expiry = null,
        string $scope = OAuth2Config::SCOPE_FULL,
        string $clientId = self::CLIENT_ID,
    ): string {
        $entity = new ApiAccessTokenEntity($issuer, $audience);
        $entity->setIdentifier(bin2hex(random_bytes(16)));
        $clientEntity = new ClientEntity();
        $clientEntity->setIdentifier($clientId);
        $clientEntity->setName('PaySubscriptions CLI');
        $entity->setClient($clientEntity);
        if (null !== $userIdentifier) {
            $entity->setUserIdentifier($userIdentifier);
        }
        $entity->addScope(new SubscriptionApiTestScope($scope));
        $entity->setExpiryDateTime($expiry ?? new DateTimeImmutable('+15 minutes'));
        $entity->setPrivateKey(new CryptKey($this->privateKeyPath()));

        return $entity->toString();
    }

    private function registerPublicClient(): void
    {
        $manager = static::getContainer()->get(ClientManagerInterface::class);

        $client = new Client('PaySubscriptions CLI', self::CLIENT_ID, null);
        $client->setRedirectUris(new RedirectUri('http://127.0.0.1/callback'));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(new Scope(OAuth2Config::SCOPE_FULL));
        $manager->save($client);
    }

    private function privateKeyPath(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir').'/tests/Fixtures/oauth/private.pem';
    }
}

final class SubscriptionApiTestScope implements ScopeEntityInterface
{
    public function __construct(private readonly string $identifier)
    {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function jsonSerialize(): string
    {
        return $this->identifier;
    }
}
