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
 * Create and delete User-owned Subscriptions through protected API v1 (issue #97).
 *
 * Seam: public HTTP interface POST /api/v1/subscriptions and
 * DELETE /api/v1/subscriptions/{id}. Behavior is observed through status,
 * content-type, and body only; the web UI keeps enforcing the same rules
 * through SubscriptionService.
 */
final class SubscriptionWriteApiTest extends DatabaseTestCase
{
    private const CLIENT_ID = 'paysubs-cli';
    private const ISSUER = 'http://localhost';

    private User $user;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('api-write-sub-user@example.com', 'Fixture-Password-1', true);
        $this->other = $this->createUser('api-write-sub-other@example.com', 'Fixture-Password-1', true);
        $this->registerPublicClient();
    }

    public function testCreateReturns201WithServerControlledFields(): void
    {
        $category = $this->createCategory($this->user, 'Video');

        $this->requestJson('POST', '/api/v1/subscriptions', [
            'name' => 'StreamFlix',
            'billingCycle' => 'monthly',
            'amount' => 15.99,
            'nextPayment' => '2026-04-05',
            'currency' => 'USD',
            'notes' => 'Family plan',
            'categoryId' => (string) $category->getId(),
            // Server-controlled fields must be ignored, never accepted.
            'id' => '0190a1b2-c3d4-7e5f-8901-23456789abcd',
            'owner' => 'api-write-sub-other@example.com',
            'convertedCurrency' => 'PLN',
            'pendingReview' => true,
            'createdAt' => '2001-01-01T00:00:00+00:00',
            'updatedAt' => '2001-01-01T00:00:00+00:00',
        ], 'api-write-sub-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('StreamFlix', $data['name']);
        self::assertSame('monthly', $data['billingCycle']);
        self::assertEqualsWithDelta(15.99, (float) $data['amount'], 0.001);
        self::assertSame('2026-04-05', $data['nextPayment']);
        self::assertSame('USD', $data['currency']);
        self::assertSame('Family plan', $data['notes']);
        self::assertSame((string) $category->getId(), $data['categoryId']);
        self::assertNotSame('0190a1b2-c3d4-7e5f-8901-23456789abcd', $data['id']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $data['id'],
        );
        self::assertNotSame('2001-01-01T00:00:00+00:00', $data['createdAt']);
        self::assertArrayHasKey('createdAt', $data);
        self::assertArrayHasKey('updatedAt', $data);
        self::assertArrayNotHasKey('owner', $data);

        // The record really belongs to the token User.
        $stored = $this->freshEm()->getRepository(Subscription::class)->find(
            \Symfony\Component\Uid\Uuid::fromString($data['id']),
        );
        self::assertNotNull($stored);
        self::assertSame('api-write-sub-user@example.com', $stored->getOwner()->getEmail());
    }

    public function testCreateIsScopedToTokenUser(): void
    {
        $category = $this->createCategory($this->user, 'Video');

        $this->requestJson('POST', '/api/v1/subscriptions', [
            'name' => 'MineFlix',
            'billingCycle' => 'monthly',
            'amount' => 9.99,
            'nextPayment' => '2026-04-05',
            'currency' => 'USD',
            'categoryId' => (string) $category->getId(),
        ], 'api-write-sub-user@example.com');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        // The other User never sees it: collection hides it, detail 404s
        // without disclosure.
        $this->client->request('GET', '/api/v1/subscriptions', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-sub-other@example.com'),
        ]);
        self::assertResponseIsSuccessful();
        self::assertNotContains('MineFlix', array_column(
            json_decode((string) $this->client->getResponse()->getContent(), true),
            'name',
        ));

        $this->client->request('GET', '/api/v1/subscriptions/'.$data['id'], [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-sub-other@example.com'),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCreateWithoutCategoryUsesDefaultCategoryRule(): void
    {
        $this->requestJson('POST', '/api/v1/subscriptions', [
            'name' => 'DefaultCat',
            'billingCycle' => 'monthly',
            'amount' => 10.0,
            'nextPayment' => '2026-04-05',
            'currency' => 'USD',
        ], 'api-write-sub-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        // The existing default-category selection and creation rule applies:
        // a fresh User gets the `Subscriptions` default.
        $default = $this->freshEm()->getRepository(ExpenseCategory::class)
            ->findOneBy(['name' => ExpenseCategory::DEFAULT_NAME]);
        self::assertNotNull($default);
        self::assertSame((string) $default->getId(), $data['categoryId']);
    }

    public function testCreateWithoutCategoryPicksExistingDefaultFirst(): void
    {
        $video = $this->createCategory($this->user, 'Video');
        $default = $this->createCategory($this->user, ExpenseCategory::DEFAULT_NAME);

        $this->requestJson('POST', '/api/v1/subscriptions', [
            'name' => 'DefaultPick',
            'billingCycle' => 'monthly',
            'amount' => 10.0,
            'nextPayment' => '2026-04-05',
            'currency' => 'USD',
        ], 'api-write-sub-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame((string) $default->getId(), $data['categoryId']);
        self::assertNotSame((string) $video->getId(), $data['categoryId']);
    }

    public function testCreateWithMissingForeignOrMalformedCategoryIdReturns422WithoutDisclosure(): void
    {
        $foreignCategory = $this->createCategory($this->other, 'Foreign');
        $missing = (string) \Symfony\Component\Uid\Uuid::v4();

        foreach ([$missing, (string) $foreignCategory->getId(), 'not-a-uuid'] as $badCategory) {
            $this->requestJson('POST', '/api/v1/subscriptions', [
                'name' => 'Bad Category',
                'billingCycle' => 'monthly',
                'amount' => 10.0,
                'nextPayment' => '2026-04-05',
                'currency' => 'USD',
                'categoryId' => $badCategory,
            ], 'api-write-sub-user@example.com');

            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, sprintf('categoryId %s must be rejected', $badCategory));
            self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
            $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertSame('validation_failed', $problem['code']);
            self::assertNotEmpty($this->violationFor($problem, 'categoryId'), 'categoryId violation expected');
            self::assertStringNotContainsStringIgnoringCase('Foreign', (string) $this->client->getResponse()->getContent());
        }
    }

    public function testCreateWithoutCurrencyDefaultsToMainCurrency(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $this->requestJson('POST', '/api/v1/subscriptions', [
            'name' => 'Defaulted Currency',
            'billingCycle' => 'monthly',
            'amount' => 10.0,
            'nextPayment' => '2026-04-05',
        ], 'api-write-sub-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('USD', $data['currency']);
        self::assertNull($data['convertedAmount']);
        self::assertFalse($data['pendingReview']);
    }

    public function testCreateWithoutCurrenciesPreservesLegacyBehavior(): void
    {
        // No confirmed main currency: legacy data stays untouched.
        $this->requestJson('POST', '/api/v1/subscriptions', [
            'name' => 'Legacy Sub',
            'billingCycle' => 'monthly',
            'amount' => 10.0,
            'nextPayment' => '2026-04-05',
        ], 'api-write-sub-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNull($data['currency']);
        self::assertNull($data['convertedAmount']);
        self::assertNull($data['convertedCurrency']);
        self::assertFalse($data['pendingReview']);
    }

    public function testCreateCrossCurrencyWithoutConvertedAmountReturns422(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $this->requestJson('POST', '/api/v1/subscriptions', [
            'name' => 'Cross Missing',
            'billingCycle' => 'monthly',
            'amount' => 10.0,
            'nextPayment' => '2026-04-05',
            'currency' => 'EUR',
        ], 'api-write-sub-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('validation_failed', $problem['code']);
        self::assertNotEmpty($this->violationFor($problem, 'convertedAmount'));
    }

    public function testCreateCrossCurrencyWithConvertedAmountStampsMainCurrency(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $this->requestJson('POST', '/api/v1/subscriptions', [
            'name' => 'Cross Reviewed',
            'billingCycle' => 'monthly',
            'amount' => 10.0,
            'nextPayment' => '2026-04-05',
            'currency' => 'EUR',
            'convertedAmount' => 11.0,
        ], 'api-write-sub-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('EUR', $data['currency']);
        self::assertEqualsWithDelta(11.0, (float) $data['convertedAmount'], 0.001);
        self::assertSame('USD', $data['convertedCurrency']);
        self::assertFalse($data['pendingReview']);
    }

    public function testCreateSameCurrencyWithConvertedAmountReturns422(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $this->requestJson('POST', '/api/v1/subscriptions', [
            'name' => 'Duplicate Converted',
            'billingCycle' => 'monthly',
            'amount' => 10.0,
            'nextPayment' => '2026-04-05',
            'currency' => 'USD',
            'convertedAmount' => 10.0,
        ], 'api-write-sub-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('validation_failed', $problem['code']);
        self::assertNotEmpty($this->violationFor($problem, 'convertedAmount'));
    }

    public function testCreateWithNonPositiveConvertedAmountReturns422(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        foreach ([0, -5.0] as $badAmount) {
            $this->requestJson('POST', '/api/v1/subscriptions', [
                'name' => 'Bad Converted',
                'billingCycle' => 'monthly',
                'amount' => 10.0,
                'nextPayment' => '2026-04-05',
                'currency' => 'EUR',
                'convertedAmount' => $badAmount,
            ], 'api-write-sub-user@example.com');

            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, sprintf('convertedAmount %s must be rejected', var_export($badAmount, true)));
            $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertNotEmpty($this->violationFor($problem, 'convertedAmount'));
        }
    }

    public function testCreateWithInvalidFieldsReturns422WithFieldViolations(): void
    {
        // Baseline valid payload; each case breaks exactly one field.
        $cases = [
            'name' => ['name' => 'AB'],
            'billingCycle' => ['billingCycle' => 'weekly'],
            'amount' => ['amount' => -3.5],
            'nextPayment' => ['nextPayment' => '2026-04-05T10:00:00+00:00'],
            'currency' => ['currency' => 'XX1'],
            'notes' => ['notes' => str_repeat('n', 2001)],
        ];

        foreach ($cases as $field => $override) {
            $payload = array_merge([
                'name' => 'Valid Name',
                'billingCycle' => 'monthly',
                'amount' => 10.0,
                'nextPayment' => '2026-04-05',
                'currency' => 'USD',
            ], $override);

            $this->requestJson('POST', '/api/v1/subscriptions', $payload, 'api-write-sub-user@example.com');

            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, sprintf('field %s must be rejected', $field));
            self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
            $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertSame('validation_failed', $problem['code']);
            self::assertNotEmpty($this->violationFor($problem, $field), sprintf('violation for %s expected', $field));
        }
    }

    public function testCreateWithMissingRequiredFieldsReturns422(): void
    {
        $this->requestJson('POST', '/api/v1/subscriptions', [], 'api-write-sub-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('validation_failed', $problem['code']);
        foreach (['name', 'billingCycle', 'amount', 'nextPayment'] as $field) {
            self::assertNotEmpty($this->violationFor($problem, $field), sprintf('violation for %s expected', $field));
        }
    }

    public function testCreateWithMalformedJsonAndArrayBodyReturns422(): void
    {
        $this->client->request('POST', '/api/v1/subscriptions', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-sub-user@example.com'),
            'CONTENT_TYPE' => 'application/json',
        ], '{not-json');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('validation_failed', $problem['code']);

        $this->client->request('POST', '/api/v1/subscriptions', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-sub-user@example.com'),
            'CONTENT_TYPE' => 'application/json',
        ], '["StreamFlix"]');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testCreateBeyondLimitReturns409(): void
    {
        $this->user->setSubscriptionsLimit(1);
        $this->em->persist($this->user);
        $this->em->flush();
        $category = $this->createCategory($this->user, 'Video');
        $this->makeSubscription($this->user, $category, 'First', BillingCycle::Monthly, '2026-03-05', 10.0);

        $this->requestJson('POST', '/api/v1/subscriptions', [
            'name' => 'Second',
            'billingCycle' => 'monthly',
            'amount' => 10.0,
            'nextPayment' => '2026-04-05',
            'currency' => 'USD',
            'categoryId' => (string) $category->getId(),
        ], 'api-write-sub-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('subscription_limit_reached', $problem['code']);
        self::assertSame(409, $problem['status']);
        self::assertArrayHasKey('type', $problem);
    }

    public function testCreateLimitIsPerUser(): void
    {
        $this->user->setSubscriptionsLimit(1);
        $this->em->persist($this->user);
        $this->em->flush();
        $category = $this->createCategory($this->user, 'Video');
        $this->makeSubscription($this->user, $category, 'First', BillingCycle::Monthly, '2026-03-05', 10.0);

        // The other User is unaffected by this User's full limit.
        $this->requestJson('POST', '/api/v1/subscriptions', [
            'name' => 'Other Sub',
            'billingCycle' => 'monthly',
            'amount' => 10.0,
            'nextPayment' => '2026-04-05',
            'currency' => 'USD',
        ], 'api-write-sub-other@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testCreateInvalidDataAtLimitReturns422(): void
    {
        $this->user->setSubscriptionsLimit(1);
        $this->em->persist($this->user);
        $this->em->flush();
        $category = $this->createCategory($this->user, 'Video');
        $this->makeSubscription($this->user, $category, 'First', BillingCycle::Monthly, '2026-03-05', 10.0);

        // Invalid data stays 422 even when the limit is also reached.
        $this->requestJson('POST', '/api/v1/subscriptions', [
            'name' => 'AB',
            'billingCycle' => 'monthly',
            'amount' => 10.0,
            'nextPayment' => '2026-04-05',
            'currency' => 'USD',
        ], 'api-write-sub-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testCreateWithoutTokenReturns401(): void
    {
        $this->requestJson('POST', '/api/v1/subscriptions', [
            'name' => 'StreamFlix',
            'billingCycle' => 'monthly',
            'amount' => 10.0,
            'nextPayment' => '2026-04-05',
        ], null);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testDeleteOwnedReturns204AndRemovesRecord(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Deletable', BillingCycle::Monthly, '2026-03-05', 10.0);
        $id = (string) $subscription->getId();

        $this->client->request('DELETE', '/api/v1/subscriptions/'.$id, [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-sub-user@example.com'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame('', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/api/v1/subscriptions/'.$id, [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-sub-user@example.com'),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDeleteForeignMissingMalformedAndRepeatedIdsReturn404(): void
    {
        $foreignCategory = $this->createCategory($this->other, 'Foreign');
        $foreign = $this->makeSubscription($this->other, $foreignCategory, 'ForeignFlix', BillingCycle::Monthly, '2026-03-05', 10.0);

        $category = $this->createCategory($this->user, 'Video');
        $temporary = $this->makeSubscription($this->user, $category, 'Temporary', BillingCycle::Monthly, '2026-03-05', 10.0);
        $temporaryId = (string) $temporary->getId();

        $token = $this->craftToken('api-write-sub-user@example.com');

        $this->client->request('DELETE', '/api/v1/subscriptions/'.$foreign->getId(), [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('subscription_not_found', $problem['code']);
        self::assertStringNotContainsStringIgnoringCase('ForeignFlix', (string) $this->client->getResponse()->getContent());

        // The foreign record is untouched.
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Subscription::class)->find($foreign->getId()));

        $missing = (string) \Symfony\Component\Uid\Uuid::v4();
        $this->client->request('DELETE', '/api/v1/subscriptions/'.$missing, [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->client->request('DELETE', '/api/v1/subscriptions/not-a-uuid', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // Deleting twice: the second DELETE 404s.
        $this->client->request('DELETE', '/api/v1/subscriptions/'.$temporaryId, [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->client->request('DELETE', '/api/v1/subscriptions/'.$temporaryId, [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDeleteWithoutTokenReturns401(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Deletable', BillingCycle::Monthly, '2026-03-05', 10.0);

        $this->client->request('DELETE', '/api/v1/subscriptions/'.$subscription->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testOpenApiDocumentCoversWriteOperations(): void
    {
        $this->client->request('GET', '/api/v1/openapi.json', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-write-sub-user@example.com'),
        ]);

        self::assertResponseIsSuccessful();
        $doc = json_decode((string) $this->client->getResponse()->getContent(), true);

        $collection = $doc['paths']['/subscriptions'];
        self::assertArrayHasKey('post', $collection);
        foreach (['201', '401', '403', '409', '422'] as $status) {
            self::assertArrayHasKey($status, $collection['post']['responses'], sprintf('POST responses must document %s', $status));
        }

        $detail = $doc['paths']['/subscriptions/{id}'];
        self::assertArrayHasKey('delete', $detail);
        foreach (['204', '401', '403', '404'] as $status) {
            self::assertArrayHasKey($status, $detail['delete']['responses'], sprintf('DELETE responses must document %s', $status));
        }

        // Write schema accepts editable data only; server-controlled fields
        // stay out of the writable contract.
        $create = $doc['components']['schemas']['SubscriptionCreate'];
        foreach (['name', 'billingCycle', 'amount', 'nextPayment'] as $field) {
            self::assertContains($field, $create['required']);
        }
        foreach (['id', 'owner', 'convertedCurrency', 'pendingReview', 'createdAt', 'updatedAt'] as $serverField) {
            self::assertArrayNotHasKey($serverField, $create['properties'], sprintf('%s must not be writable', $serverField));
        }

        foreach (['ValidationFailed', 'SubscriptionNotFound', 'SubscriptionLimitReached'] as $name) {
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
        $entity->addScope(new SubscriptionWriteApiTestScope($scope));
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

final class SubscriptionWriteApiTestScope implements ScopeEntityInterface
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
