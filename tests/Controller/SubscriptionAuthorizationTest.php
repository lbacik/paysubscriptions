<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Subscription;
use App\Entity\User;
use App\Factory\SubscriptionFactory;
use App\Factory\UserFactory;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class SubscriptionAuthorizationTest extends WebTestCase
{
    use ResetDatabase;
    use Factories;

    private static function createTestClient(): KernelBrowser
    {
        return static::createClient();
    }



    public function testOwnerCanOpenEditForm(): void
    {
        $client = self::createTestClient();
        $subscription = $this->createSubscriptionForNewUser('owner@example.com', 'Owner Netflix');

        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/subscription/'.$subscription->getId().'/edit');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Owner Netflix', (string) $client->getResponse()->getContent());
    }

    public function testOwnerCanUpdateOwnSubscription(): void
    {
        $client = self::createTestClient();
        $subscription = $this->createSubscriptionForNewUser('owner@example.com', 'Owner Netflix');
        $id = (string) $subscription->getId();

        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/subscription/'.$id.'/edit');
        $form = $client->getCrawler()->selectButton('Update')->form();
        $form['subscription[name]'] = 'Owner Netflix Renamed';
        $client->submit($form);

        self::assertResponseRedirects('/dashboard');
        self::assertSame('Owner Netflix Renamed', $this->freshSubscription($client, $id)->getName());
    }

    public function testOwnerCanOpenDeleteForm(): void
    {
        $client = self::createTestClient();
        $subscription = $this->createSubscriptionForNewUser('owner@example.com', 'Owner Netflix');

        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/subscription/'.$subscription->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Owner Netflix', (string) $client->getResponse()->getContent());
    }

    public function testOwnerCanDeleteOwnSubscription(): void
    {
        $client = self::createTestClient();
        $subscription = $this->createSubscriptionForNewUser('owner@example.com', 'Owner Netflix');
        $id = (string) $subscription->getId();

        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/subscription/'.$id);
        self::assertResponseIsSuccessful();
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $client->request('POST', '/subscription/'.$id, ['_token' => $token]);

        self::assertResponseRedirects('/dashboard');
        self::assertNull($this->freshSubscription($client, $id));
    }

    public function testIntruderCannotOpenEditForm(): void
    {
        $client = self::createTestClient();
        $subscription = $this->createSubscriptionForNewUser('owner@example.com', 'Victim Netflix');
        UserFactory::createOne(['email' => 'intruder@example.com', 'isVerified' => true]);

        $this->primeSession($client);
        $this->loginAs($client, 'intruder@example.com');
        $client->request('GET', '/subscription/'.$subscription->getId().'/edit');

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('Victim Netflix', (string) $client->getResponse()->getContent());
    }

    public function testIntruderCannotUpdateSubscription(): void
    {
        $client = self::createTestClient();
        $subscription = $this->createSubscriptionForNewUser('owner@example.com', 'Victim Netflix');
        $id = (string) $subscription->getId();
        UserFactory::createOne(['email' => 'intruder@example.com', 'isVerified' => true]);

        $this->primeSession($client);
        $this->loginAs($client, 'intruder@example.com');
        $client->request('POST', '/subscription/'.$id.'/edit', [
            'subscription' => $this->intruderEditPayload($client),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('Victim Netflix', (string) $client->getResponse()->getContent());

        $fresh = $this->freshSubscription($client, $id);
        self::assertNotNull($fresh);
        self::assertSame('Victim Netflix', $fresh->getName());
        self::assertEquals($subscription->getMonthly(), $fresh->getMonthly());
        self::assertEquals($subscription->getYearly(), $fresh->getYearly());
    }

    public function testIntruderCannotUpdateSubscriptionViaTurbo(): void
    {
        $client = self::createTestClient();
        $subscription = $this->createSubscriptionForNewUser('owner@example.com', 'Victim Netflix');
        $id = (string) $subscription->getId();
        UserFactory::createOne(['email' => 'intruder@example.com', 'isVerified' => true]);

        $this->primeSession($client);
        $this->loginAs($client, 'intruder@example.com');
        $client->request(
            'POST',
            '/subscription/'.$id.'/edit',
            [
                'subscription' => $this->intruderEditPayload($client),
            ],
            [],
            ['HTTP_Turbo-Frame' => 'modal']
        );

        self::assertResponseStatusCodeSame(403);

        $fresh = $this->freshSubscription($client, $id);
        self::assertNotNull($fresh);
        self::assertSame('Victim Netflix', $fresh->getName());
    }

    public function testIntruderCannotOpenDeleteForm(): void
    {
        $client = self::createTestClient();
        $subscription = $this->createSubscriptionForNewUser('owner@example.com', 'Victim Netflix');
        UserFactory::createOne(['email' => 'intruder@example.com', 'isVerified' => true]);

        $this->primeSession($client);
        $this->loginAs($client, 'intruder@example.com');
        $client->request('GET', '/subscription/'.$subscription->getId());

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('Victim Netflix', (string) $client->getResponse()->getContent());
    }

    public function testIntruderCannotDeleteSubscription(): void
    {
        $client = self::createTestClient();
        $subscription = $this->createSubscriptionForNewUser('owner@example.com', 'Victim Netflix');
        $id = (string) $subscription->getId();
        UserFactory::createOne(['email' => 'intruder@example.com', 'isVerified' => true]);

        $this->primeSession($client);
        $this->loginAs($client, 'intruder@example.com');
        $client->request('POST', '/subscription/'.$id, ['_token' => $this->csrfToken($client, 'delete'.$id)]);

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('Victim Netflix', (string) $client->getResponse()->getContent());

        $fresh = $this->freshSubscription($client, $id);
        self::assertNotNull($fresh);
        self::assertSame('Victim Netflix', $fresh->getName());
    }

    public function testIntruderCannotDeleteSubscriptionViaTurbo(): void
    {
        $client = self::createTestClient();
        $subscription = $this->createSubscriptionForNewUser('owner@example.com', 'Victim Netflix');
        $id = (string) $subscription->getId();
        UserFactory::createOne(['email' => 'intruder@example.com', 'isVerified' => true]);

        $this->primeSession($client);
        $this->loginAs($client, 'intruder@example.com');
        $client->request(
            'POST',
            '/subscription/'.$id,
            ['_token' => $this->csrfToken($client, 'delete'.$id)],
            [],
            ['HTTP_Turbo-Frame' => 'modal']
        );

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('Victim Netflix', (string) $client->getResponse()->getContent());

        $fresh = $this->freshSubscription($client, $id);
        self::assertNotNull($fresh);
        self::assertSame('Victim Netflix', $fresh->getName());
    }

    private function createSubscriptionForNewUser(string $email, string $name): Subscription
    {
        $owner = UserFactory::createOne(['email' => $email, 'isVerified' => true]);

        $subscription = SubscriptionFactory::createOne([
            'owner' => $owner,
            'name' => $name,
        ]);

        return $subscription->_real();
    }

    private function loginAs(KernelBrowser $client, string $email): void
    {
        $user = $client->getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        $client->loginUser($user);
    }

    private function freshSubscription(KernelBrowser $client, string $id): ?Subscription
    {
        // The client's kernel is rebooted between requests, so always resolve
        // services from its current container rather than a stale one.
        $container = $client->getContainer();
        $container->get('doctrine')->getManager()->clear();

        return $container->get(SubscriptionRepository::class)->find($id);
    }

    /**
     * A well-formed edit payload an intruder would submit: valid field values
     * with a CSRF token from their own session, so a denial proves ownership
     * was checked rather than the form rejected.
     */
    private function intruderEditPayload(KernelBrowser $client): array
    {
        return [
            'name' => 'Hacked',
            'firstPayment' => '2024-01-01',
            'monthly' => '99.99',
            'yearly' => '',
            '_token' => $this->csrfToken($client, 'subscription'),
        ];
    }

    private function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        $container = $client->getContainer();
        $session = $client->getSession();
        self::assertNotNull($session);

        // The kernel pops each request off its stack once handled, so outside
        // a request the session-backed CSRF storage has no session to read.
        // Push a scratch request carrying the primed session instead.
        $requestStack = $container->get('request_stack');
        $requestStack->push($request = new Request());
        $request->setSession($session);

        try {
            return $container->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        } finally {
            $requestStack->pop();
            $session->save();
        }
    }

    /**
     * Creates an (empty) session and pins the client to it via a cookie, so
     * later logins and CSRF tokens share one session. Must run before login:
     * the firewall persists its token into the current session.
     */
    private function primeSession(KernelBrowser $client): void
    {
        $session = $client->getSession();
        self::assertNotNull($session);
        $session->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId(), null, '/', 'localhost'));
    }
}
