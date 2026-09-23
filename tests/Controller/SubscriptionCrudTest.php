<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Subscription;
use App\Tests\DatabaseTestCase;

/**
 * Subscription entry, validation, limits and ownership.
 *
 * Every subscription belongs to exactly one User: the suite proves a second
 * user can neither read, change nor delete another user's subscriptions
 * through the HTTP layer (edit/delete answer 404 for foreign rows).
 */
final class SubscriptionCrudTest extends DatabaseTestCase
{
    private const PASSWORD = 'Fixture-Password-1';

    public function testAnonymousUserIsBouncedFromSubscriptionPages(): void
    {
        $this->client->request('GET', '/subscription/new');

        self::assertResponseRedirects('/login');
    }

    public function testAuthenticatedUserCanOpenTheCreationForm(): void
    {
        $this->createUser('alice@example.com', self::PASSWORD, true);
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $this->client->request('GET', '/subscription/new');

        self::assertResponseIsSuccessful();
    }

    public function testUserCanCreateAMonthlySubscription(): void
    {
        $this->createUser('alice@example.com', self::PASSWORD, true);
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/subscription/new');
        $form = $crawler->selectButton('Save')->form([
            'subscription[name]' => 'Netflix',
            'subscription[firstPayment]' => '2024-01-15',
            'subscription[monthly]' => '15.99',
        ]);
        // The yearly field renders with an empty value; leave it empty.
        $form['subscription[yearly]']->setValue('');
        $this->client->submit($form);

        self::assertResponseRedirects('/dashboard');

        $stored = $this->em->getRepository(Subscription::class)->findBy(['name' => 'Netflix']);
        self::assertCount(1, $stored);
        self::assertSame(15.99, $stored[0]->getMonthly());
        self::assertNull($stored[0]->getYearly());
        self::assertSame('alice@example.com', $stored[0]->getOwner()->getEmail());
    }

    public function testUserCanCreateAYearlySubscription(): void
    {
        $this->createUser('alice@example.com', self::PASSWORD, true);
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/subscription/new');
        $form = $crawler->selectButton('Save')->form([
            'subscription[name]' => 'Amazon Prime',
            'subscription[firstPayment]' => '2024-05-01',
            'subscription[yearly]' => '139.00',
        ]);
        $form['subscription[monthly]']->setValue('');
        $this->client->submit($form);

        self::assertResponseRedirects('/dashboard');

        $stored = $this->em->getRepository(Subscription::class)->findBy(['name' => 'Amazon Prime']);
        self::assertCount(1, $stored);
        self::assertNull($stored[0]->getMonthly());
        self::assertSame(139.0, $stored[0]->getYearly());
    }

    public function testBothMonthlyAndYearlyIsRejected(): void
    {
        $this->createUser('alice@example.com', self::PASSWORD, true);
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/subscription/new');
        $form = $crawler->selectButton('Save')->form([
            'subscription[name]' => 'Greedy Service',
            'subscription[firstPayment]' => '2024-01-15',
            'subscription[monthly]' => '10',
            'subscription[yearly]' => '100',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(Subscription::class)->findAll());
    }

    public function testNeitherMonthlyNorYearlyIsRejected(): void
    {
        $this->createUser('alice@example.com', self::PASSWORD, true);
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/subscription/new');
        $form = $crawler->selectButton('Save')->form([
            'subscription[name]' => 'Free Service',
            'subscription[firstPayment]' => '2024-01-15',
        ]);
        $form['subscription[monthly]']->setValue('');
        $form['subscription[yearly]']->setValue('');
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(Subscription::class)->findAll());
    }

    public function testTooShortANameIsRejected(): void
    {
        $this->createUser('alice@example.com', self::PASSWORD, true);
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/subscription/new');
        $form = $crawler->selectButton('Save')->form([
            'subscription[name]' => 'AB',
            'subscription[firstPayment]' => '2024-01-15',
            'subscription[monthly]' => '10',
        ]);
        $form['subscription[yearly]']->setValue('');
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(Subscription::class)->findAll());
    }

    public function testUserCanEditTheirOwnSubscription(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $subscription = $this->createSubscription($alice, 'Netflix', 15.99, null);
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/subscription/'.$subscription->getId().'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Update')->form([
            'subscription[name]' => 'Netflix Premium',
            'subscription[monthly]' => '22.99',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/dashboard');

        $this->em->clear();
        $updated = $this->em->getRepository(Subscription::class)->find($subscription->getId());
        self::assertSame('Netflix Premium', $updated->getName());
        self::assertSame(22.99, $updated->getMonthly());
    }

    public function testUserCanDeleteTheirOwnSubscription(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $subscription = $this->createSubscription($alice, 'Netflix', 15.99, null);
        $id = (string) $subscription->getId();
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/subscription/'.$id);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/subscription/'.$id, [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
        ]);

        self::assertResponseRedirects('/dashboard');
        $this->em->clear();
        self::assertNull($this->em->getRepository(Subscription::class)->find($id));
    }

    public function testDeleteWithAnInvalidCsrfTokenKeepsTheSubscription(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $subscription = $this->createSubscription($alice, 'Netflix', 15.99, null);
        $id = (string) $subscription->getId();
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $this->client->request('POST', '/subscription/'.$id, ['_token' => 'bogus-token']);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Subscription::class)->find($id));
    }

    public function testSecondUserCannotOpenAnotherUsersSubscriptionForEditing(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $subscription = $this->createSubscription($alice, 'Netflix', 15.99, null);

        $this->createUser('bob@example.com', self::PASSWORD, true);
        $this->login('bob@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $this->client->request('GET', '/subscription/'.$subscription->getId().'/edit');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSecondUserCannotUpdateAnotherUsersSubscription(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $subscription = $this->createSubscription($alice, 'Netflix', 15.99, null);

        $this->createUser('bob@example.com', self::PASSWORD, true);
        $this->login('bob@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/dashboard');
        // Sanity: bob's dashboard must not leak alice's row or its edit link.
        self::assertStringNotContainsString('Netflix', $crawler->text(null, true));

        $this->client->request(
            'POST',
            '/subscription/'.$subscription->getId().'/edit',
            ['subscription' => [
                'name' => 'Hijacked',
                'firstPayment' => '2024-01-15',
                'monthly' => '1.00',
                'yearly' => '',
                '_token' => 'bogus-token',
            ]],
        );

        self::assertResponseStatusCodeSame(404);

        $this->em->clear();
        $untouched = $this->em->getRepository(Subscription::class)->find($subscription->getId());
        self::assertSame('Netflix', $untouched->getName());
        self::assertSame(15.99, $untouched->getMonthly());
    }

    public function testSecondUserCannotDeleteAnotherUsersSubscription(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $subscription = $this->createSubscription($alice, 'Netflix', 15.99, null);
        $id = (string) $subscription->getId();

        $this->createUser('bob@example.com', self::PASSWORD, true);
        $this->login('bob@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $this->client->request('GET', '/subscription/'.$id);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/subscription/'.$id, [
            '_token' => 'bogus-token',
        ]);
        self::assertResponseStatusCodeSame(404);

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Subscription::class)->find($id));
    }

    public function testSecondUserDoesNotSeeAnotherUsersSubscriptionsOnTheDashboard(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $this->createSubscription($alice, 'Alice Secret Service', 9.99, null);

        $this->createUser('bob@example.com', self::PASSWORD, true);
        $this->login('bob@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Alice Secret Service', $crawler->text(null, true));
    }

    public function testSubscriptionLimitBlocksCreation(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $alice->setSubscriptionsLimit(1);
        $this->em->flush();
        $this->createSubscription($alice, 'Only Slot', 5.0, null);

        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/subscription/new');
        $form = $crawler->selectButton('Save')->form([
            'subscription[name]' => 'One Too Many',
            'subscription[firstPayment]' => '2024-01-15',
            'subscription[monthly]' => '5.00',
        ]);
        $form['subscription[yearly]']->setValue('');
        $this->client->submit($form);

        self::assertResponseRedirects('/dashboard');
        self::assertCount(1, $this->em->getRepository(Subscription::class)->findAll());
    }

    public function testSubscriptionLimitIsPerUser(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $alice->setSubscriptionsLimit(1);
        $this->em->flush();
        $this->createSubscription($alice, 'Only Slot', 5.0, null);

        // Bob has the default limit untouched by alice's usage.
        $this->createUser('bob@example.com', self::PASSWORD, true);
        $this->login('bob@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/subscription/new');
        $form = $crawler->selectButton('Save')->form([
            'subscription[name]' => 'Bob Service',
            'subscription[firstPayment]' => '2024-01-15',
            'subscription[monthly]' => '5.00',
        ]);
        $form['subscription[yearly]']->setValue('');
        $this->client->submit($form);

        self::assertResponseRedirects('/dashboard');
        self::assertCount(1, $this->em->getRepository(Subscription::class)->findBy(['name' => 'Bob Service']));
    }
}
