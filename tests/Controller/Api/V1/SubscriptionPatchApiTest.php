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
 * Partially update User-owned Subscriptions through protected API v1 (issue #98).
 *
 * Seam: public HTTP interface PATCH /api/v1/subscriptions/{id}. Behavior is
 * observed through status, content-type, and body only; the web UI keeps
 * enforcing the same rules through SubscriptionService.
 */
final class SubscriptionPatchApiTest extends DatabaseTestCase
{
    private const CLIENT_ID = 'paysubs-cli';
    private const ISSUER = 'http://localhost';

    private User $user;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('api-patch-sub-user@example.com', 'Fixture-Password-1', true);
        $this->other = $this->createUser('api-patch-sub-other@example.com', 'Fixture-Password-1', true);
        $this->registerPublicClient();
    }

    public function testPatchNameOnlyPreservesOtherFields(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Old Name', BillingCycle::Monthly, '2026-03-05', 10.0, 'USD', null, null, 'Keep me');

        $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [
            'name' => 'New Name',
        ], 'api-patch-sub-user@example.com');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('New Name', $data['name']);
        self::assertSame('monthly', $data['billingCycle']);
        self::assertEqualsWithDelta(10.0, (float) $data['amount'], 0.001);
        self::assertSame('2026-03-05', $data['nextPayment']);
        self::assertSame('USD', $data['currency']);
        self::assertSame('Keep me', $data['notes']);
        self::assertSame((string) $category->getId(), $data['categoryId']);
        self::assertSame((string) $subscription->getId(), $data['id']);
    }

    public function testPatchWithEmptyObjectReturns422(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Old Name', BillingCycle::Monthly, '2026-03-05', 10.0);

        $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [], 'api-patch-sub-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('validation_failed', $problem['code']);
    }

    public function testPatchWithOnlyIgnoredFieldsReturns422(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Old Name', BillingCycle::Monthly, '2026-03-05', 10.0);

        $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [
            'owner' => 'api-patch-sub-other@example.com',
            'convertedCurrency' => 'PLN',
        ], 'api-patch-sub-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testPatchWithMalformedJsonReturns422(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Old Name', BillingCycle::Monthly, '2026-03-05', 10.0);

        $this->client->request('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-patch-sub-user@example.com'),
            'CONTENT_TYPE' => 'application/json',
        ], '{not-json');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('validation_failed', $problem['code']);
    }

    public function testPatchRequiredFieldsCannotBeCleared(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Old Name', BillingCycle::Monthly, '2026-03-05', 10.0, 'USD');

        foreach (['name', 'billingCycle', 'amount', 'nextPayment', 'categoryId'] as $field) {
            $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [
                $field => null,
            ], 'api-patch-sub-user@example.com');

            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, sprintf('field %s must not be clearable', $field));
            $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertSame('validation_failed', $problem['code']);
            self::assertNotEmpty($this->violationFor($problem, $field), sprintf('violation for %s expected', $field));
        }

        $stored = $this->freshEm()->getRepository(Subscription::class)->find($subscription->getId());
        self::assertSame('Old Name', $stored->getName());
        self::assertSame(BillingCycle::Monthly, $stored->getBillingCycle());
        self::assertSame(10.0, $stored->getAmount());
        self::assertSame('2026-03-05', $stored->getNextPayment()->format('Y-m-d'));
    }

    public function testPatchIgnoresServerControlledFields(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Old Name', BillingCycle::Monthly, '2026-03-05', 10.0);
        $id = (string) $subscription->getId();

        $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [
            'name' => 'New Name',
            'id' => '0190a1b2-c3d4-7e5f-8901-23456789abcd',
            'owner' => 'api-patch-sub-other@example.com',
            'convertedCurrency' => 'PLN',
            'pendingReview' => true,
            'createdAt' => '2001-01-01T00:00:00+00:00',
            'updatedAt' => '2001-01-01T00:00:00+00:00',
        ], 'api-patch-sub-user@example.com');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('New Name', $data['name']);
        self::assertSame($id, $data['id']);
        self::assertArrayNotHasKey('owner', $data);

        $stored = $this->freshEm()->getRepository(Subscription::class)->find($subscription->getId());
        self::assertSame('api-patch-sub-user@example.com', $stored->getOwner()->getEmail());
    }

    public function testPatchNotesCanBeCleared(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Noted', BillingCycle::Monthly, '2026-03-05', 10.0, 'USD', null, null, 'Some notes');

        $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [
            'notes' => null,
        ], 'api-patch-sub-user@example.com');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNull($data['notes']);

        $stored = $this->freshEm()->getRepository(Subscription::class)->find($subscription->getId());
        self::assertNull($stored->getNotes());
    }

    public function testPatchConvertedAmountCanBeClearedWhenResultStaysValid(): void
    {
        // No confirmed main currency: legacy converted input carries no
        // requirement, so clearing it stays valid.
        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Legacy', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');

        $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [
            'convertedAmount' => null,
        ], 'api-patch-sub-user@example.com');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNull($data['convertedAmount']);
        self::assertNull($data['convertedCurrency']);
    }

    public function testPatchConvertedAmountClearWhenCrossCurrencyReturns422AndLeavesRecordUnchanged(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Cross', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');

        $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [
            'convertedAmount' => null,
        ], 'api-patch-sub-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('validation_failed', $problem['code']);
        self::assertNotEmpty($this->violationFor($problem, 'convertedAmount'));

        $stored = $this->freshEm()->getRepository(Subscription::class)->find($subscription->getId());
        self::assertSame(11.0, $stored->getConvertedAmount());
        self::assertSame('USD', $stored->getConvertedCurrency());
    }

    public function testPatchInvalidValuesReturn422AndLeaveRecordUnchanged(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Old Name', BillingCycle::Monthly, '2026-03-05', 10.0, 'USD', null, null, 'Keep me');

        $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [
            'name' => 'AB',
            'billingCycle' => 'weekly',
            'amount' => -3.5,
            'nextPayment' => '2026-04-05T10:00:00+00:00',
            'currency' => 'XX1',
            'notes' => str_repeat('n', 2001),
        ], 'api-patch-sub-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('validation_failed', $problem['code']);
        foreach (['name', 'billingCycle', 'amount', 'nextPayment', 'currency', 'notes'] as $field) {
            self::assertNotEmpty($this->violationFor($problem, $field), sprintf('violation for %s expected', $field));
        }

        // Atomic: nothing was applied, not even the valid-adjacent notes.
        $stored = $this->freshEm()->getRepository(Subscription::class)->find($subscription->getId());
        self::assertSame('Old Name', $stored->getName());
        self::assertSame('Keep me', $stored->getNotes());
        self::assertSame(10.0, $stored->getAmount());
    }

    public function testUnrelatedUpdatePreservesPendingReview(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Stale', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');

        // The main currency moves on: the stored figure is now stale.
        $this->user->setMainCurrency('PLN');
        $this->em->flush();
        $this->em->clear();

        $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [
            'notes' => 'Unrelated edit',
        ], 'api-patch-sub-user@example.com');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Unrelated edit', $data['notes']);
        self::assertTrue($data['pendingReview']);
        self::assertEqualsWithDelta(11.0, (float) $data['convertedAmount'], 0.001);
        self::assertSame('USD', $data['convertedCurrency']);
    }

    public function testCurrencyChangeDropsOldConvertedFigure(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Cross', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');

        // A figure computed for EUR must never be reused for GBP: changing the
        // currency while supplying the replacement reviews the new figure.
        $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [
            'currency' => 'GBP',
            'convertedAmount' => 12.0,
        ], 'api-patch-sub-user@example.com');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('GBP', $data['currency']);
        self::assertEqualsWithDelta(12.0, (float) $data['convertedAmount'], 0.001);
        self::assertSame('USD', $data['convertedCurrency']);
        self::assertFalse($data['pendingReview']);
    }

    public function testCurrencyChangeWithoutReplacementConvertedAmountReturns422(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Cross', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');

        $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [
            'currency' => 'GBP',
        ], 'api-patch-sub-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $stored = $this->freshEm()->getRepository(Subscription::class)->find($subscription->getId());
        self::assertSame('EUR', $stored->getCurrency());
        self::assertSame(11.0, $stored->getConvertedAmount());
        self::assertSame('USD', $stored->getConvertedCurrency());
    }

    public function testConfirmConvertedForReviewsEvenUnchangedValue(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Stale', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');

        $this->user->setMainCurrency('PLN');
        $this->em->flush();
        $this->em->clear();

        // The numeric value is unchanged, yet the explicit confirmation
        // reviews it against the current main currency.
        $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [
            'convertedAmount' => 11.0,
            'confirmConvertedFor' => 'PLN',
        ], 'api-patch-sub-user@example.com');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertEqualsWithDelta(11.0, (float) $data['convertedAmount'], 0.001);
        self::assertSame('PLN', $data['convertedCurrency']);
        self::assertFalse($data['pendingReview']);
        self::assertArrayNotHasKey('confirmConvertedFor', $data);

        $stored = $this->freshEm()->getRepository(Subscription::class)->find($subscription->getId());
        self::assertSame('PLN', $stored->getConvertedCurrency());
    }

    public function testConfirmConvertedForMismatchReturns409AndLeavesRecordUnchanged(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Stale', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');

        $this->user->setMainCurrency('PLN');
        $this->em->flush();
        $this->em->clear();

        $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [
            'name' => 'Should Not Apply',
            'convertedAmount' => 11.0,
            'confirmConvertedFor' => 'USD',
        ], 'api-patch-sub-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('currency_conflict', $problem['code']);
        self::assertSame(409, $problem['status']);
        self::assertArrayHasKey('type', $problem);

        // Atomic: the conflict applied nothing, not even the unrelated name.
        $stored = $this->freshEm()->getRepository(Subscription::class)->find($subscription->getId());
        self::assertSame('Stale', $stored->getName());
        self::assertSame('USD', $stored->getConvertedCurrency());
        self::assertSame(11.0, $stored->getConvertedAmount());
    }

    public function testConfirmConvertedForWithoutConvertedAmountReturns422(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Cross', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');

        $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [
            'confirmConvertedFor' => 'USD',
        ], 'api-patch-sub-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('validation_failed', $problem['code']);
        self::assertNotEmpty($this->violationFor($problem, 'confirmConvertedFor'));
    }

    public function testConfirmConvertedForWithInvalidCodeReturns422(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Cross', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');

        $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [
            'convertedAmount' => 11.0,
            'confirmConvertedFor' => 'XX1',
        ], 'api-patch-sub-user@example.com');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNotEmpty($this->violationFor($problem, 'confirmConvertedFor'));
    }

    public function testChangedConvertedAmountWithoutConfirmStampsCurrentMainCurrency(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Cross', BillingCycle::Monthly, '2026-03-05', 10.0, 'EUR', 11.0, 'USD');

        $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [
            'convertedAmount' => 12.5,
        ], 'api-patch-sub-user@example.com');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertEqualsWithDelta(12.5, (float) $data['convertedAmount'], 0.001);
        self::assertSame('USD', $data['convertedCurrency']);
        self::assertFalse($data['pendingReview']);
    }

    public function testPatchCategoryChangeRequiresOwnership(): void
    {
        $own = $this->createCategory($this->user, 'Video');
        $target = $this->createCategory($this->user, 'Music');
        $foreign = $this->createCategory($this->other, 'Foreign');
        $subscription = $this->makeSubscription($this->user, $own, 'Movable', BillingCycle::Monthly, '2026-03-05', 10.0);

        $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [
            'categoryId' => (string) $target->getId(),
        ], 'api-patch-sub-user@example.com');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame((string) $target->getId(), $data['categoryId']);

        $missing = (string) \Symfony\Component\Uid\Uuid::v4();
        foreach ([$missing, (string) $foreign->getId(), 'not-a-uuid'] as $badCategory) {
            $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [
                'categoryId' => $badCategory,
            ], 'api-patch-sub-user@example.com');

            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, sprintf('categoryId %s must be rejected', $badCategory));
            $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertSame('validation_failed', $problem['code']);
            self::assertNotEmpty($this->violationFor($problem, 'categoryId'));
            self::assertStringNotContainsStringIgnoringCase('Foreign', (string) $this->client->getResponse()->getContent());
        }

        // The failed attempts left the owned category in place.
        $stored = $this->freshEm()->getRepository(Subscription::class)->find($subscription->getId());
        self::assertSame((string) $target->getId(), (string) $stored->getCategory()->getId());
    }

    public function testPatchForeignMissingAndMalformedIdsReturn404(): void
    {
        $foreignCategory = $this->createCategory($this->other, 'Foreign');
        $foreign = $this->makeSubscription($this->other, $foreignCategory, 'ForeignFlix', BillingCycle::Monthly, '2026-03-05', 10.0);

        $token = $this->craftToken('api-patch-sub-user@example.com');

        $this->client->request('PATCH', '/api/v1/subscriptions/'.$foreign->getId(), [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
        ], '{"name":"Intrusion"}');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('subscription_not_found', $problem['code']);
        self::assertStringNotContainsStringIgnoringCase('ForeignFlix', (string) $this->client->getResponse()->getContent());

        $missing = (string) \Symfony\Component\Uid\Uuid::v4();
        $this->client->request('PATCH', '/api/v1/subscriptions/'.$missing, [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
        ], '{"name":"Ghost"}');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->client->request('PATCH', '/api/v1/subscriptions/not-a-uuid', [], [], [
            'HTTP_Authorization' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
        ], '{"name":"Ghost"}');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // The foreign record is untouched.
        $this->em->clear();
        $stored = $this->em->getRepository(Subscription::class)->find($foreign->getId());
        self::assertSame('ForeignFlix', $stored->getName());
    }

    public function testPatchWithoutTokenReturns401(): void
    {
        $category = $this->createCategory($this->user, 'Video');
        $subscription = $this->makeSubscription($this->user, $category, 'Old Name', BillingCycle::Monthly, '2026-03-05', 10.0);

        $this->requestJson('PATCH', '/api/v1/subscriptions/'.$subscription->getId(), [
            'name' => 'New Name',
        ], null);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testOpenApiDocumentCoversPatchOperation(): void
    {
        $this->client->request('GET', '/api/v1/openapi.json', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('api-patch-sub-user@example.com'),
        ]);

        self::assertResponseIsSuccessful();
        $doc = json_decode((string) $this->client->getResponse()->getContent(), true);

        $detail = $doc['paths']['/subscriptions/{id}'];
        self::assertArrayHasKey('patch', $detail);
        foreach (['200', '401', '403', '404', '409', '422'] as $status) {
            self::assertArrayHasKey($status, $detail['patch']['responses'], sprintf('PATCH responses must document %s', $status));
        }

        // The update schema is partial and keeps server-controlled fields out;
        // confirmConvertedFor is write-only and never appears on reads.
        $update = $doc['components']['schemas']['SubscriptionUpdate'];
        self::assertArrayNotHasKey('required', $update);
        foreach (['name', 'billingCycle', 'amount', 'nextPayment', 'currency', 'convertedAmount', 'confirmConvertedFor', 'categoryId', 'notes'] as $field) {
            self::assertArrayHasKey($field, $update['properties'], sprintf('%s must be patchable', $field));
        }
        foreach (['id', 'owner', 'convertedCurrency', 'pendingReview', 'createdAt', 'updatedAt'] as $serverField) {
            self::assertArrayNotHasKey($serverField, $update['properties'], sprintf('%s must not be writable', $serverField));
        }
        self::assertArrayNotHasKey('confirmConvertedFor', $doc['components']['schemas']['Subscription']['properties']);

        self::assertArrayHasKey('CurrencyConflict', $doc['components']['responses']);
        self::assertArrayHasKey(
            'application/problem+json',
            $doc['components']['responses']['CurrencyConflict']['content'],
        );
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

        $body = [] === $payload ? '{}' : (string) json_encode($payload);
        $this->client->request($method, $uri, [], [], $server, $body);
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
        $entity->addScope(new SubscriptionPatchApiTestScope($scope));
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

final class SubscriptionPatchApiTestScope implements ScopeEntityInterface
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
