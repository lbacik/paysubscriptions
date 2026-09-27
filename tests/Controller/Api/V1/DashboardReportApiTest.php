<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1;

use App\Entity\User;
use App\Enum\BillingCycle;
use App\OAuth2\ApiAccessTokenEntity;
use App\OAuth2\OAuth2Config;
use App\Service\ChartService;
use App\Service\ExpenseCategoryService;
use App\Service\SubscriptionService;
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
 * Dashboard-equivalent summaries and charts through API v1 (issue #99).
 *
 * Seam: public HTTP interface GET /api/v1/reports/dashboard.
 * Behavior is observed through status, content-type, and body only.
 * Every mode and basis is compared against the existing dashboard
 * calculations (SubscriptionService::getTotals, ChartService, and the
 * DashboardController visibility rules).
 */
final class DashboardReportApiTest extends DatabaseTestCase
{
    private const CLIENT_ID = 'paysubs-cli';
    private const ISSUER = 'http://localhost';

    private User $user;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('api-report-user@example.com', 'Fixture-Password-1', true);
        $this->other = $this->createUser('api-report-other@example.com', 'Fixture-Password-1', true);
        $this->registerPublicClient();
    }

    public function testWithoutTokenReturnsProblemUnauthorized(): void
    {
        $this->client->request('GET', '/api/v1/reports/dashboard');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(401, $problem['status']);
        self::assertArrayHasKey('code', $problem);
    }

    public function testWithWrongScopeReturnsProblemForbidden(): void
    {
        $this->client->request('GET', '/api/v1/reports/dashboard', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-report-user@example.com', scope: 'api:read'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('insufficient_scope', $problem['code']);
    }

    public function testDefaultBarReportMatchesDashboardTotalsAndChart(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();
        $this->createSubscription($this->user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-01-15'));
        $this->createSubscription($this->user, 'Amazon Prime', BillingCycle::Yearly, 120.0, new \DateTime('2024-05-01'));
        $this->createSubscription($this->other, 'Foreign', BillingCycle::Monthly, 999.0, new \DateTime('2024-01-15'));

        $report = $this->getReport('api-report-user@example.com');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        // Summary mirrors SubscriptionService::getTotals() plus the User limit.
        $expected = $this->subscriptions()->getTotals(
            $this->subscriptions()->get($this->user, 'name', 'asc', null, 'USD'),
            'USD',
        );
        self::assertEqualsWithDelta($expected['monthly'], $report['summary']['monthly'], 0.001);
        self::assertEqualsWithDelta($expected['yearly'], $report['summary']['yearly'], 0.001);
        self::assertEqualsWithDelta($expected['monthlyCalculated'], $report['summary']['monthlyEquivalent'], 0.001);
        self::assertEqualsWithDelta($expected['yearlyCalculated'], $report['summary']['yearlyEquivalent'], 0.001);
        self::assertSame($expected['count'], $report['summary']['count']);
        self::assertSame($expected['pendingReview'], $report['summary']['pendingReview']);
        self::assertSame('USD', $report['summary']['currency']);
        self::assertSame($this->user->getSubscriptionsLimit(), $report['summary']['limit']);

        // Monthly: 15.99 direct + 120/12 normalized = 25.99.
        self::assertEqualsWithDelta(25.99, $report['summary']['monthlyEquivalent'], 0.001);
        // Yearly: 120 direct + 15.99*12 normalized = 311.88.
        self::assertEqualsWithDelta(311.88, $report['summary']['yearlyEquivalent'], 0.001);

        // The other User's subscription never contributes.
        self::assertSame(2, $report['summary']['count']);

        // Bar chart numeric data mirrors ChartService without presentation options.
        $chart = $this->charts()->createBarChart(
            $this->subscriptions()->get($this->user, 'name', 'asc', null, 'USD'),
            'USD',
        );
        $chartData = $chart->getData();
        self::assertSame('bar', $report['chart']['mode']);
        self::assertSame('charges', $report['chart']['basis']);
        self::assertSame('USD', $report['chart']['currency']);
        self::assertSame($chartData['labels'], $report['chart']['labels']);
        self::assertCount(count($chartData['datasets']), $report['chart']['datasets']);
        foreach ($chartData['datasets'] as $i => $dataset) {
            self::assertSame($dataset['label'], $report['chart']['datasets'][$i]['label']);
            foreach ($dataset['data'] as $m => $value) {
                self::assertEqualsWithDelta($value, $report['chart']['datasets'][$i]['data'][$m], 0.001);
            }
            self::assertArrayNotHasKey('backgroundColor', $report['chart']['datasets'][$i]);
        }
        self::assertFalse($report['chart']['empty']);
        self::assertSame(0, $report['chart']['hiddenCount']);
        self::assertNull($report['chart']['hiddenCycle']);
    }

    /**
     * @dataProvider doughnutModes
     */
    public function testDoughnutModesMatchDashboardChartsAndVisibility(
        string $chartMode,
        string $basis,
        string $expectedBasis,
    ): void {
        $this->user->setMainCurrency('USD');
        $this->em->flush();
        $this->createSubscription($this->user, 'Netflix', BillingCycle::Monthly, 12.0, new \DateTime('2024-01-15'));
        $this->createSubscription($this->user, 'Amazon Prime', BillingCycle::Yearly, 120.0, new \DateTime('2024-05-01'));

        $query = http_build_query(['chartMode' => $chartMode, 'basis' => $basis]);
        $report = $this->getReport('api-report-user@example.com', $query);

        self::assertResponseIsSuccessful();

        $subscriptions = $this->subscriptions()->get($this->user, 'name', 'asc', null, 'USD');
        $withCalculated = $basis === 'equivalent';
        $chart = match ($chartMode) {
            'monthly' => $this->charts()->createMonthlyChart($subscriptions, $withCalculated, null, 'USD'),
            default => $this->charts()->createYearlyChart($subscriptions, $withCalculated, 'USD'),
        };
        $chartData = $chart->getData();

        self::assertSame($chartMode, $report['chart']['mode']);
        self::assertSame($expectedBasis, $report['chart']['basis']);
        self::assertSame('USD', $report['chart']['currency']);
        self::assertSame($chartData['labels'], $report['chart']['labels']);
        self::assertCount(count($chartData['datasets'][0]['data']), $report['chart']['data']);
        foreach ($chartData['datasets'][0]['data'] as $i => $value) {
            self::assertEqualsWithDelta((float) $value, (float) $report['chart']['data'][$i], 0.001);
        }

        // Visibility metadata mirrors DashboardController::describeChart().
        if ($chartMode === 'monthly' && $basis === 'direct') {
            self::assertSame(1, $report['chart']['hiddenCount']);
            self::assertSame('yearly', $report['chart']['hiddenCycle']);
            self::assertFalse($report['chart']['empty']);
        } elseif ($chartMode === 'yearly' && $basis === 'direct') {
            self::assertSame(1, $report['chart']['hiddenCount']);
            self::assertSame('monthly', $report['chart']['hiddenCycle']);
            self::assertFalse($report['chart']['empty']);
        } else {
            self::assertSame(0, $report['chart']['hiddenCount']);
            self::assertNull($report['chart']['hiddenCycle']);
            self::assertFalse($report['chart']['empty']);
        }
    }

    public static function doughnutModes(): array
    {
        return [
            'monthly direct' => ['monthly', 'direct', 'monthly_direct'],
            'monthly equivalent' => ['monthly', 'equivalent', 'monthly_equivalent'],
            'yearly direct' => ['yearly', 'direct', 'yearly_direct'],
            'yearly equivalent' => ['yearly', 'equivalent', 'yearly_equivalent'],
        ];
    }

    public function testCategoryFilterNarrowsReportToOwnedCategory(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();
        $categories = static::getContainer()->get(ExpenseCategoryService::class);
        $food = $categories->create($this->user, 'Food', '#ff0000');
        $fun = $categories->create($this->user, 'Fun', '#00ff00');

        $monthly = $this->createSubscription($this->user, 'Groceries', BillingCycle::Monthly, 50.0, new \DateTime('2024-01-15'));
        $monthly->setCategory($food);
        $yearly = $this->createSubscription($this->user, 'Cinema', BillingCycle::Yearly, 120.0, new \DateTime('2024-05-01'));
        $yearly->setCategory($fun);
        $this->em->flush();

        $query = http_build_query(['categoryId' => (string) $food->getId()]);
        $report = $this->getReport('api-report-user@example.com', $query);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $report['summary']['count']);
        self::assertEqualsWithDelta(50.0, $report['summary']['monthly'], 0.001);
        self::assertEqualsWithDelta(0.0, $report['summary']['yearly'], 0.001);
        self::assertSame((string) $food->getId(), $report['filters']['categoryId']);

        $expected = $this->subscriptions()->getTotals(
            $this->subscriptions()->get($this->user, 'name', 'asc', (string) $food->getId(), 'USD'),
            'USD',
        );
        self::assertSame($expected['count'], $report['summary']['count']);
        self::assertEqualsWithDelta($expected['monthlyCalculated'], $report['summary']['monthlyEquivalent'], 0.001);
    }

    public function testForeignCategoryFilterReturnsProblemNotFound(): void
    {
        $categories = static::getContainer()->get(ExpenseCategoryService::class);
        $foreign = $categories->create($this->other, 'Foreign', '#333333');

        $this->client->request(
            'GET',
            '/api/v1/reports/dashboard?'.http_build_query(['categoryId' => (string) $foreign->getId()]),
            [],
            [],
            ['HTTP_Authorization' => 'Bearer '.$this->craftToken('api-report-user@example.com')],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('category_not_found', $problem['code']);
    }

    public function testMalformedCategoryFilterReturnsProblemNotFound(): void
    {
        $this->client->request(
            'GET',
            '/api/v1/reports/dashboard?'.http_build_query(['categoryId' => 'not-a-uuid']),
            [],
            [],
            ['HTTP_Authorization' => 'Bearer '.$this->craftToken('api-report-user@example.com')],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('category_not_found', $problem['code']);
    }

    public function testMonthSelectorAppliesOnlyToMonthlyDirect(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();
        $this->createSubscription($this->user, 'Netflix', BillingCycle::Monthly, 10.0, new \DateTime('2024-01-15'));
        $this->createSubscription($this->user, 'May Yearly', BillingCycle::Yearly, 120.0, new \DateTime('2024-05-15'));

        $may = $this->getReport(
            'api-report-user@example.com',
            http_build_query(['chartMode' => 'monthly', 'basis' => 'direct', 'month' => 5]),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(['May Yearly', 'Netflix'], $this->sorted($may['chart']['labels']));
        self::assertSame(5, $may['filters']['month']);
        self::assertSame(0, $may['chart']['hiddenCount']);

        $june = $this->getReport(
            'api-report-user@example.com',
            http_build_query(['chartMode' => 'monthly', 'basis' => 'direct', 'month' => 6]),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(['Netflix'], $june['chart']['labels']);
        self::assertSame(1, $june['chart']['hiddenCount']);
        self::assertSame('yearly', $june['chart']['hiddenCycle']);

        // Summary always covers the whole filtered set, never the month slice.
        self::assertSame($may['summary']['count'], $june['summary']['count']);
        self::assertEqualsWithDelta($may['summary']['monthlyEquivalent'], $june['summary']['monthlyEquivalent'], 0.001);
    }

    /**
     * @dataProvider invalidFilters
     */
    public function testInvalidFiltersReturnProblemError(string $query): void
    {
        $this->client->request(
            'GET',
            '/api/v1/reports/dashboard?'.$query,
            [],
            [],
            ['HTTP_Authorization' => 'Bearer '.$this->craftToken('api-report-user@example.com')],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(422, $problem['status']);
        self::assertSame('invalid_filter', $problem['code']);
    }

    public static function invalidFilters(): array
    {
        return [
            'unknown chart mode' => ['chartMode=weekly'],
            'unknown basis' => ['chartMode=monthly&basis=annual'],
            'equivalent basis with bar' => ['chartMode=bar&basis=equivalent'],
            'month zero' => ['chartMode=monthly&basis=direct&month=0'],
            'month thirteen' => ['chartMode=monthly&basis=direct&month=13'],
            'month not a number' => ['chartMode=monthly&basis=direct&month=may'],
            'month with bar' => ['chartMode=bar&month=5'],
            'month with yearly' => ['chartMode=yearly&month=5'],
            'month with monthly equivalent' => ['chartMode=monthly&basis=equivalent&month=5'],
        ];
    }

    public function testNoConfirmedMainCurrencyKeepsUnlabeledTotals(): void
    {
        // No main currency confirmed: legacy amounts pass through unchanged.
        $this->createSubscription($this->user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-01-15'));
        $this->createSubscription($this->user, 'Amazon Prime', BillingCycle::Yearly, 120.0, new \DateTime('2024-05-01'));

        $report = $this->getReport('api-report-user@example.com');

        self::assertResponseIsSuccessful();
        self::assertNull($report['summary']['currency']);
        self::assertNull($report['chart']['currency']);
        self::assertSame(2, $report['summary']['count']);
        self::assertSame(0, $report['summary']['pendingReview']);
        self::assertEqualsWithDelta(25.99, $report['summary']['monthlyEquivalent'], 0.001);
        self::assertEqualsWithDelta(311.88, $report['summary']['yearlyEquivalent'], 0.001);

        $expected = $this->subscriptions()->getTotals(
            $this->subscriptions()->get($this->freshOwner(), 'name', 'asc', null, null),
            null,
        );
        self::assertEqualsWithDelta($expected['monthlyCalculated'], $report['summary']['monthlyEquivalent'], 0.001);
    }

    public function testPendingReviewExcludedFromMoneyButKeptInCount(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $fresh = $this->createSubscription($this->user, 'Local', BillingCycle::Monthly, 10.0, new \DateTime('2024-01-15'));
        $fresh->setCurrency('USD');
        $stale = $this->createSubscription($this->user, 'Stale', BillingCycle::Monthly, 20.0, new \DateTime('2024-01-15'));
        $stale->setCurrency('EUR');
        $stale->setConvertedAmount(22.0);
        $stale->setConvertedCurrency('PLN');
        $this->em->flush();

        $report = $this->getReport('api-report-user@example.com');

        self::assertResponseIsSuccessful();
        self::assertSame(2, $report['summary']['count']);
        self::assertSame(1, $report['summary']['pendingReview']);
        // Only the fresh subscription contributes money.
        self::assertEqualsWithDelta(10.0, $report['summary']['monthly'], 0.001);
        self::assertEqualsWithDelta(10.0, $report['summary']['monthlyEquivalent'], 0.001);

        // Charts draw only the reportable subscription.
        self::assertSame(1, count($report['chart']['datasets']));
        self::assertSame('Local', $report['chart']['datasets'][0]['label']);

        $monthly = $this->getReport(
            'api-report-user@example.com',
            http_build_query(['chartMode' => 'monthly', 'basis' => 'equivalent']),
        );
        self::assertSame(['Local'], $monthly['chart']['labels']);
        self::assertEqualsWithDelta(10.0, (float) $monthly['chart']['data'][0], 0.001);

        $expected = $this->subscriptions()->getTotals(
            $this->subscriptions()->get($this->freshOwner(), 'name', 'asc', null, 'USD'),
            'USD',
        );
        self::assertSame($expected['pendingReview'], $report['summary']['pendingReview']);
        self::assertEqualsWithDelta($expected['monthly'], $report['summary']['monthly'], 0.001);
    }

    public function testEmptyReportRendersZeroTotalsAndEmptyCharts(): void
    {
        $report = $this->getReport('api-report-user@example.com');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $report['summary']['count']);
        self::assertSame(0, $report['summary']['pendingReview']);
        self::assertEqualsWithDelta(0.0, $report['summary']['monthlyEquivalent'], 0.001);
        self::assertTrue($report['chart']['empty']);
    }

    public function testOpenApiDocumentDescribesReportParametersAndResponses(): void
    {
        $this->client->request('GET', '/api/v1/openapi.json', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-report-user@example.com'),
        ]);

        self::assertResponseIsSuccessful();

        $doc = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('/reports/dashboard', $doc['paths']);

        $get = $doc['paths']['/reports/dashboard']['get'];
        $paramNames = array_column($get['parameters'], 'name');
        foreach (['chartMode', 'basis', 'categoryId', 'month'] as $name) {
            self::assertContains($name, $paramNames);
        }

        foreach (['200', '401', '403', '404', '422'] as $status) {
            self::assertArrayHasKey($status, $get['responses']);
        }

        self::assertArrayHasKey('DashboardReport', $doc['components']['schemas']);
        self::assertArrayHasKey('InvalidFilter', $doc['components']['responses']);
    }

    /**
     * @return array<string, mixed>
     */
    private function getReport(string $email, string $query = ''): array
    {
        $this->client->request(
            'GET',
            '/api/v1/reports/dashboard'.('' === $query ? '' : '?'.$query),
            [],
            [],
            ['HTTP_Authorization' => 'Bearer '.$this->craftToken($email)],
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function freshOwner(): User
    {
        $user = $this->freshUser('api-report-user@example.com');
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function subscriptions(): SubscriptionService
    {
        return static::getContainer()->get(SubscriptionService::class);
    }

    private function charts(): ChartService
    {
        return static::getContainer()->get(ChartService::class);
    }

    /**
     * @param list<string> $labels
     *
     * @return list<string>
     */
    private function sorted(array $labels): array
    {
        sort($labels);

        return $labels;
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
        $entity->addScope(new ReportApiTestScope($scope));
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

final class ReportApiTestScope implements ScopeEntityInterface
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
