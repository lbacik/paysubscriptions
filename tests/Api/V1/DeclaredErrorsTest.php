<?php

declare(strict_types=1);

namespace App\Tests\Api\V1;

use App\Entity\ExpenseCategory;
use App\Entity\Subscription;
use App\Enum\BillingCycle;
use App\Service\ExpenseCategoryService;
use Symfony\Component\Uid\Uuid;

/**
 * Declared errors match emitted errors (issue #280, parent #276): each error
 * case is provoked over HTTP, and the published document must declare that
 * status on that operation with the returned problem `type` in the
 * response's named examples.
 *
 * The cases reuse the published-document helper introduced by #277. The 422
 * `type` is per-occurrence (`/validation_errors/{id}`), so those cases match
 * on the stable prefix and on the violation `propertyPath` each named
 * example covers. The reverse direction ("every declared status is
 * exercised") is deliberately left out as too brittle.
 */
final class DeclaredErrorsTest extends ApiV1TestCase
{
    private const SUBSCRIPTIONS = '/api/v1/subscriptions';
    private const CATEGORIES = '/api/v1/expense-categories';
    private const DASHBOARD = '/api/v1/reports/dashboard';
    private const MERGE_PATCH = ['content-type' => 'application/merge-patch+json'];

    private const EXPECTED_V1_OPERATIONS = [
        'api_v1_reports_dashboard',
        'api_v1expense-categories_get_collection',
        'api_v1expense-categories_post',
        'api_v1expense-categories_id_get',
        'api_v1expense-categories_id_delete',
        'api_v1expense-categories_id_patch',
        'api_v1_subscriptions_list',
        'api_v1_subscriptions_create',
        'api_v1_subscriptions_show',
        'api_v1_subscriptions_delete',
        'api_v1_subscriptions_update',
    ];

    public function testEveryV1OperationDeclares401And403(): void
    {
        $document = OpenApiContract::document($this->client);

        $operationIds = [];
        foreach ($document['paths'] as $path => $operations) {
            if (!\is_string($path) || !str_starts_with($path, '/api/v1') || !\is_array($operations)) {
                continue;
            }
            foreach ($operations as $operation) {
                if (\is_array($operation) && isset($operation['operationId'])) {
                    $operationIds[] = $operation['operationId'];

                    $responses = $operation['responses'] ?? [];
                    self::assertArrayHasKey('401', $responses, sprintf('Operation "%s" must declare 401.', $operation['operationId']));
                    self::assertArrayHasKey('403', $responses, sprintf('Operation "%s" must declare 403.', $operation['operationId']));

                    $unauthorized = $responses['401'];
                    self::assertArrayHasKey('WWW-Authenticate', $unauthorized['headers'] ?? [], sprintf('Operation "%s" 401 must declare the WWW-Authenticate header.', $operation['operationId']));
                    self::assertContains('/errors/401', self::exampleTypes($unauthorized), sprintf('Operation "%s" 401 must list /errors/401 in its examples.', $operation['operationId']));

                    $forbiddenTypes = self::exampleTypes($responses['403']);
                    self::assertContains('/problems/insufficient-scope', $forbiddenTypes, sprintf('Operation "%s" 403 must list insufficient-scope in its examples.', $operation['operationId']));
                    self::assertContains('/problems/account-inactive', $forbiddenTypes, sprintf('Operation "%s" 403 must list account-inactive in its examples.', $operation['operationId']));
                }
            }
        }

        sort($operationIds);
        $expected = self::EXPECTED_V1_OPERATIONS;
        sort($expected);
        self::assertSame($expected, $operationIds, 'The published contract must carry exactly the 11 known /api/v1 operations.');
    }

    public function testUnauthorizedIsDeclared(): void
    {
        $this->client->request('GET', self::SUBSCRIPTIONS);

        self::assertResponseStatusCodeSame(401);
        self::assertJsonContains(['type' => '/errors/401', 'status' => 401]);
        $this->assertDeclaredWithType('api_v1_subscriptions_list', '401', '/errors/401');
    }

    public function testInsufficientScopeIsDeclared(): void
    {
        $user = $this->createUser('declared-errors-scope@example.com');

        $this->client->request('GET', self::SUBSCRIPTIONS, ['auth_bearer' => $this->craftToken($user->getUserIdentifier(), scope: 'api:read')]);

        self::assertResponseStatusCodeSame(403);
        self::assertJsonContains(['type' => '/problems/insufficient-scope', 'status' => 403]);
        $this->assertDeclaredWithType('api_v1_subscriptions_list', '403', '/problems/insufficient-scope');
    }

    public function testAccountInactiveIsDeclared(): void
    {
        $unverified = $this->createUser('declared-errors-unverified@example.com', verified: false);

        $this->requestAs($unverified, 'GET', self::SUBSCRIPTIONS);

        self::assertResponseStatusCodeSame(403);
        self::assertJsonContains(['type' => '/problems/account-inactive', 'status' => 403]);
        $this->assertDeclaredWithType('api_v1_subscriptions_list', '403', '/problems/account-inactive');
    }

    public function testSubscriptionLimitIsDeclared(): void
    {
        $user = $this->createUser('declared-errors-limit@example.com');
        $user->setSubscriptionsLimit(1);
        $this->em->persist($user);
        $this->em->flush();

        $categories = static::getContainer()->get(ExpenseCategoryService::class);
        $category = $categories->create($user, 'Video', '#111111');
        $this->persistSubscription($user, $category, 'First');

        $this->requestAs($user, 'POST', self::SUBSCRIPTIONS, ['json' => [
            'name' => 'Second',
            'billingCycle' => 'monthly',
            'amount' => '10.00',
            'nextPayment' => '2026-03-05',
            'currency' => 'USD',
            'category' => self::CATEGORIES.'/'.$category->getId(),
        ]]);

        self::assertResponseStatusCodeSame(409);
        self::assertJsonContains(['type' => '/problems/subscription-limit-reached', 'status' => 409]);
        $this->assertDeclaredWithType('api_v1_subscriptions_create', '409', '/problems/subscription-limit-reached');

        // Confirmed against the Subscription tests: a create carries no
        // confirmation, so it never returns currency-conflict — only the
        // update declares it.
        self::assertNotContains('/problems/currency-conflict', self::exampleTypes($this->declaredResponse('api_v1_subscriptions_create', '409')));
    }

    public function testCurrencyConflictIsDeclared(): void
    {
        $user = $this->createUser('declared-errors-currency@example.com');
        $user->setMainCurrency('USD');
        $this->em->flush();

        $categories = static::getContainer()->get(ExpenseCategoryService::class);
        $category = $categories->create($user, 'Video', '#111111');
        $subscription = $this->persistSubscription($user, $category, 'Stale', 'EUR', 11.0, 'USD');

        $user->setMainCurrency('PLN');
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        $this->requestAs($user, 'PATCH', self::SUBSCRIPTIONS.'/'.$subscription->getId(), [
            'json' => ['convertedAmount' => '11.00', 'confirmConvertedFor' => 'USD'],
            'headers' => self::MERGE_PATCH,
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertJsonContains(['type' => '/problems/currency-conflict', 'status' => 409]);
        $this->assertDeclaredWithType('api_v1_subscriptions_update', '409', '/problems/currency-conflict');
    }

    public function testCategoryInUseIsDeclared(): void
    {
        $user = $this->createUser('declared-errors-category@example.com');

        $categories = static::getContainer()->get(ExpenseCategoryService::class);
        $category = $categories->create($user, 'Video', '#111111');
        $this->persistSubscription($user, $category, 'Keeps Category');

        $this->requestAs($user, 'DELETE', self::CATEGORIES.'/'.$category->getId());

        self::assertResponseStatusCodeSame(409);
        self::assertJsonContains(['type' => '/problems/category-in-use', 'status' => 409]);
        $this->assertDeclaredWithType('api_v1expense-categories_id_delete', '409', '/problems/category-in-use');
    }

    public function testSubscriptionList422IsDeclared(): void
    {
        $user = $this->createUser('declared-errors-list@example.com');

        foreach (['order[bogus]=asc' => 'order', 'category=not-a-uuid' => 'category'] as $query => $path) {
            $response = $this->requestAs($user, 'GET', self::SUBSCRIPTIONS.'?'.$query);
            $problem = json_decode($response->getContent(false), true);

            self::assertSame(422, $response->getStatusCode(), sprintf('Query "%s" must be rejected.', $query));
            self::assertStringStartsWith('/validation_errors/', $problem['type']);
            self::assertContains($path, array_column($problem['violations'] ?? [], 'propertyPath'));
        }

        $declared = $this->declaredResponse('api_v1_subscriptions_list', '422');
        self::assert422ExamplesCover($declared, ['order', 'category']);
    }

    public function testDashboard422IsDeclaredAnd404IsGone(): void
    {
        $user = $this->createUser('declared-errors-dashboard@example.com');

        foreach ([
            'chartMode=weekly' => 'chartMode',
            'chartMode=monthly&basis=annual' => 'basis',
            'chartMode=monthly&month=13' => 'month',
            'category=not-a-uuid' => 'category',
        ] as $query => $path) {
            $response = $this->requestAs($user, 'GET', self::DASHBOARD.'?'.$query);
            $problem = json_decode($response->getContent(false), true);

            self::assertSame(422, $response->getStatusCode(), sprintf('Query "%s" must be rejected.', $query));
            self::assertStringStartsWith('/validation_errors/', $problem['type']);
            self::assertContains($path, array_column($problem['violations'] ?? [], 'propertyPath'));
        }

        $declared = $this->declaredResponse('api_v1_reports_dashboard', '422');
        self::assert422ExamplesCover($declared, ['chartMode', 'basis', 'month', 'category']);

        $document = OpenApiContract::document($this->client);
        $dashboard = self::findOperation($document, 'api_v1_reports_dashboard');
        self::assertArrayNotHasKey('404', $dashboard['responses'] ?? [], 'The Dashboard report never 404s and must not declare it.');
    }

    /**
     * @return array<string, mixed>
     */
    private function declaredResponse(string $operationId, string $status): array
    {
        $operation = self::findOperation(OpenApiContract::document($this->client), $operationId);
        self::assertArrayHasKey($status, $operation['responses'] ?? [], sprintf('Operation "%s" must declare status %s.', $operationId, $status));

        return $operation['responses'][$status];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function findOperation(array $document, string $operationId): array
    {
        foreach ($document['paths'] ?? [] as $operations) {
            if (!\is_array($operations)) {
                continue;
            }
            foreach ($operations as $candidate) {
                if (\is_array($candidate) && ($candidate['operationId'] ?? null) === $operationId) {
                    return $candidate;
                }
            }
        }

        self::fail(sprintf('Operation "%s" must be declared in the published document.', $operationId));
    }

    private function assertDeclaredWithType(string $operationId, string $status, string $type): void
    {
        self::assertContains($type, self::exampleTypes($this->declaredResponse($operationId, $status)), sprintf('Operation "%s" status %s must list "%s" in its response examples.', $operationId, $status, $type));
    }

    /**
     * @param list<string> $paths
     */
    private static function assert422ExamplesCover(array $response, array $paths): void
    {
        $examples = $response['content']['application/problem+json']['examples'] ?? null;
        self::assertIsArray($examples, 'The 422 response must carry named examples on application/problem+json.');
        self::assertNotEmpty($examples);

        $covered = [];
        foreach ($examples as $name => $example) {
            $value = $example['value'] ?? null;
            self::assertIsArray($value, sprintf('Example "%s" must be a full problem document.', $name));
            self::assertStringStartsWith('/validation_errors/', $value['type'] ?? '', sprintf('Example "%s" must carry a validation problem type.', $name));
            foreach ($value['violations'] ?? [] as $violation) {
                $covered[] = $violation['propertyPath'];
            }
        }

        foreach ($paths as $path) {
            self::assertContains($path, $covered, sprintf('The 422 examples must cover violations on "%s".', $path));
        }
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return list<string>
     */
    private static function exampleTypes(array $response): array
    {
        $examples = $response['content']['application/problem+json']['examples'] ?? [];
        $types = [];
        foreach ($examples as $example) {
            if (isset($example['value']['type'])) {
                $types[] = $example['value']['type'];
            }
        }

        return $types;
    }

    private function persistSubscription(
        \App\Entity\User $user,
        ExpenseCategory $category,
        string $name,
        ?string $currency = null,
        ?float $convertedAmount = null,
        ?string $convertedCurrency = null,
    ): Subscription {
        $subscription = (new Subscription())
            ->setName($name)
            ->setBillingCycle(BillingCycle::Monthly)
            ->setNextPayment(new \DateTime('2026-03-05'))
            ->setAmount(10.0)
            ->setCurrency($currency);
        $subscription->setConvertedAmount($convertedAmount);
        $subscription->setConvertedCurrency($convertedCurrency);
        $user->addSubscription($subscription);
        $subscription->setCategory($category);

        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }
}
