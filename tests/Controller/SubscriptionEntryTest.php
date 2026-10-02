<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use App\Factory\ExpenseCategoryFactory;
use App\Factory\SubscriptionFactory;
use App\Factory\UserFactory;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Behavioral coverage for Subscription entry and editing (#40): add/edit of
 * every approved field, invalid input feedback, conditional converted
 * amounts, cross-user category rejection, notes escaping, and the per-User
 * Subscription limit.
 */
final class SubscriptionEntryTest extends WebTestCase
{
    use ResetDatabase;
    use Factories;

    public function testUserCanCreateSubscriptionWithAllFields(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne([
            'email' => 'creator@example.com',
            'isVerified' => true,
            'mainCurrency' => 'PLN',
        ]);
        $category = ExpenseCategoryFactory::createOne(['owner' => $owner]);

        $this->loginAs($client, 'creator@example.com');
        $crawler = $client->request('GET', '/subscription/new');
        self::assertResponseIsSuccessful();
        // The notes placeholder is a resolved sentence, not a raw key: form
        // attr values are not translated automatically.
        self::assertStringContainsString(
            'Anything worth remembering',
            (string) $client->getResponse()->getContent()
        );

        $form = $crawler->selectButton('Save')->form([
            'subscription[name]' => 'Netflix',
            'subscription[billingCycle]' => BillingCycle::Monthly->value,
            'subscription[amount]' => '49.99',
            'subscription[nextPayment]' => '2026-10-15',
            'subscription[category]' => (string) $category->getId(),
            'subscription[currency]' => 'PLN',
            'subscription[notes]' => 'Family plan, cancel before December.',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/dashboard');

        $stored = $this->findSubscriptionByName($client, 'Netflix');
        self::assertNotNull($stored);
        self::assertSame(BillingCycle::Monthly, $stored->getBillingCycle());
        self::assertEquals(49.99, $stored->getAmount());
        self::assertSame('2026-10-15', $stored->getNextPayment()->format('Y-m-d'));
        self::assertSame((string) $category->getId(), (string) $stored->getCategory()->getId());
        self::assertSame('PLN', $stored->getCurrency());
        self::assertSame('Family plan, cancel before December.', $stored->getNotes());
    }

    public function testUserCanEditEveryFieldAndCorrectNextPaymentDate(): void
    {
        $client = static::createClient();
        $subscription = $this->createSubscriptionForNewUser('editor@example.com', 'Old Name');
        $id = (string) $subscription->getId();
        $owner = $subscription->getOwner();
        \assert($owner instanceof User);
        $otherCategory = ExpenseCategoryFactory::createOne(['owner' => $owner]);

        $this->loginAs($client, 'editor@example.com');
        $crawler = $client->request('GET', '/subscription/'.$id.'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Update')->form([
            'subscription[name]' => 'New Name',
            'subscription[billingCycle]' => BillingCycle::Yearly->value,
            'subscription[amount]' => '120.00',
            'subscription[nextPayment]' => '2027-01-31',
            'subscription[category]' => (string) $otherCategory->getId(),
            'subscription[notes]' => 'Corrected renewal date.',
        ]);
        // Currency select keeps its current value; only override supported fields.
        $client->submit($form);

        self::assertResponseRedirects('/dashboard');

        $fresh = $this->freshSubscription($client, $id);
        self::assertNotNull($fresh);
        self::assertSame('New Name', $fresh->getName());
        self::assertSame(BillingCycle::Yearly, $fresh->getBillingCycle());
        self::assertEquals(120.00, $fresh->getAmount());
        self::assertSame('2027-01-31', $fresh->getNextPayment()->format('Y-m-d'));
        self::assertSame((string) $otherCategory->getId(), (string) $fresh->getCategory()->getId());
        self::assertSame('Corrected renewal date.', $fresh->getNotes());
    }

    public function testInvalidInputReturnsUsefulValidationFeedback(): void
    {
        $client = static::createClient();
        $this->createSubscriptionForNewUser('invalid@example.com', 'Keeps Name');

        $this->loginAs($client, 'invalid@example.com');
        $crawler = $client->request('GET', '/subscription/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form([
            'subscription[name]' => '',
            'subscription[amount]' => '-5',
            'subscription[nextPayment]' => '',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $content = (string) $client->getResponse()->getContent();
        // The re-rendered form explains what is wrong instead of failing silently.
        self::assertTrue(
            str_contains($content, 'This value should not be blank.')
            || str_contains($content, 'This value is too short.')
            || str_contains($content, 'This value should be positive.'),
            'Expected a validation message in the re-rendered form.'
        );

        self::assertNull($this->findSubscriptionByName($client, ''));
    }

    public function testConvertedAmountRequiredOnlyWhenCurrenciesDiffer(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne([
            'email' => 'fx@example.com',
            'isVerified' => true,
            'mainCurrency' => 'PLN',
        ]);
        $category = ExpenseCategoryFactory::createOne(['owner' => $owner]);
        $categoryId = (string) $category->getId();

        $this->loginAs($client, 'fx@example.com');

        // Same currency without a converted amount: valid.
        $crawler = $client->request('GET', '/subscription/new');
        $client->submit($crawler->selectButton('Save')->form([
            'subscription[name]' => 'Same Currency Sub',
            'subscription[billingCycle]' => BillingCycle::Monthly->value,
            'subscription[amount]' => '10.00',
            'subscription[nextPayment]' => '2026-11-01',
            'subscription[category]' => $categoryId,
            'subscription[currency]' => 'PLN',
            'subscription[notes]' => '',
        ]));
        self::assertResponseRedirects('/dashboard');
        self::assertNotNull($this->findSubscriptionByName($client, 'Same Currency Sub'));

        // Cross-currency without a converted amount: rejected with guidance.
        $crawler = $client->request('GET', '/subscription/new');
        $client->submit($crawler->selectButton('Save')->form([
            'subscription[name]' => 'Cross Currency Sub',
            'subscription[billingCycle]' => BillingCycle::Monthly->value,
            'subscription[amount]' => '10.00',
            'subscription[nextPayment]' => '2026-11-01',
            'subscription[category]' => $categoryId,
            'subscription[currency]' => 'EUR',
            'subscription[notes]' => '',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'converted amount',
            strtolower((string) $client->getResponse()->getContent())
        );
        self::assertNull($this->findSubscriptionByName($client, 'Cross Currency Sub'));

        // Cross-currency with a converted amount: valid.
        $crawler = $client->request('GET', '/subscription/new');
        $client->submit($crawler->selectButton('Save')->form([
            'subscription[name]' => 'Cross Currency Sub',
            'subscription[billingCycle]' => BillingCycle::Monthly->value,
            'subscription[amount]' => '10.00',
            'subscription[nextPayment]' => '2026-11-01',
            'subscription[category]' => $categoryId,
            'subscription[currency]' => 'EUR',
            'subscription[convertedAmount]' => '43.21',
            'subscription[notes]' => '',
        ]));
        self::assertResponseRedirects('/dashboard');
        $stored = $this->findSubscriptionByName($client, 'Cross Currency Sub');
        self::assertNotNull($stored);
        self::assertEquals(43.21, $stored->getConvertedAmount());

        // Same currency must not carry a converted amount.
        $crawler = $client->request('GET', '/subscription/new');
        $client->submit($crawler->selectButton('Save')->form([
            'subscription[name]' => 'Duplicate Converted Sub',
            'subscription[billingCycle]' => BillingCycle::Monthly->value,
            'subscription[amount]' => '10.00',
            'subscription[nextPayment]' => '2026-11-01',
            'subscription[category]' => $categoryId,
            'subscription[currency]' => 'PLN',
            'subscription[convertedAmount]' => '43.21',
            'subscription[notes]' => '',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->findSubscriptionByName($client, 'Duplicate Converted Sub'));
    }

    public function testCreateWithForeignCategoryIsRejected(): void
    {
        $client = static::createClient();
        $this->createSubscriptionForNewUser('owner@example.com', 'Owner Netflix');

        $stranger = UserFactory::createOne(['email' => 'stranger@example.com', 'isVerified' => true]);
        $foreignCategory = ExpenseCategoryFactory::createOne(['owner' => $stranger]);

        $this->loginAs($client, 'owner@example.com');
        $client->request('POST', '/subscription/new', [
            'subscription' => [
                'name' => 'Sneaky Sub',
                'billingCycle' => BillingCycle::Monthly->value,
                'amount' => '9.99',
                'nextPayment' => '2026-10-01',
                // Forged raw choice value: the rendered select only lists the
                // owner's own categories, so a hand-crafted request carries
                // another user's category id.
                'category' => (string) $foreignCategory->getId(),
                'notes' => '',
                '_token' => $this->csrfToken($client, 'subscription'),
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->findSubscriptionByName($client, 'Sneaky Sub'));
    }

    public function testSubscriptionLimitIsEnforced(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne([
            'email' => 'limited@example.com',
            'isVerified' => true,
            'mainCurrency' => 'PLN',
        ]);
        $category = ExpenseCategoryFactory::createOne(['owner' => $owner]);

        $this->setSubscriptionLimit($client, 'limited@example.com', 1);

        $this->loginAs($client, 'limited@example.com');

        $crawler = $client->request('GET', '/subscription/new');
        $client->submit($crawler->selectButton('Save')->form([
            'subscription[name]' => 'First Sub',
            'subscription[billingCycle]' => BillingCycle::Monthly->value,
            'subscription[amount]' => '10.00',
            'subscription[nextPayment]' => '2026-11-01',
            'subscription[category]' => (string) $category->getId(),
            'subscription[currency]' => 'PLN',
            'subscription[notes]' => '',
        ]));
        self::assertResponseRedirects('/dashboard');
        self::assertNotNull($this->findSubscriptionByName($client, 'First Sub'));

        // A second subscription exceeds the per-User limit.
        $crawler = $client->request('GET', '/subscription/new');
        $client->submit($crawler->selectButton('Save')->form([
            'subscription[name]' => 'Second Sub',
            'subscription[billingCycle]' => BillingCycle::Monthly->value,
            'subscription[amount]' => '10.00',
            'subscription[nextPayment]' => '2026-11-01',
            'subscription[category]' => (string) $category->getId(),
            'subscription[currency]' => 'PLN',
            'subscription[notes]' => '',
        ]));

        self::assertResponseRedirects('/dashboard');
        $client->followRedirect();
        self::assertStringContainsStringIgnoringCase(
            'maximum number of subscriptions',
            (string) $client->getResponse()->getContent()
        );
        self::assertNull($this->findSubscriptionByName($client, 'Second Sub'));
    }

    public function testNotesAreOptionalPlainTextAndSafelyEscaped(): void
    {
        $client = static::createClient();
        $owner = UserFactory::createOne([
            'email' => 'notes@example.com',
            'isVerified' => true,
            'mainCurrency' => 'PLN',
        ]);
        $category = ExpenseCategoryFactory::createOne(['owner' => $owner]);

        $this->loginAs($client, 'notes@example.com');

        // Notes are optional: omitting them still creates the Subscription.
        $crawler = $client->request('GET', '/subscription/new');
        $client->submit($crawler->selectButton('Save')->form([
            'subscription[name]' => 'No Notes Sub',
            'subscription[billingCycle]' => BillingCycle::Monthly->value,
            'subscription[amount]' => '10.00',
            'subscription[nextPayment]' => '2026-11-01',
            'subscription[category]' => (string) $category->getId(),
            'subscription[currency]' => 'PLN',
            'subscription[notes]' => '',
        ]));
        self::assertResponseRedirects('/dashboard');
        $withoutNotes = $this->findSubscriptionByName($client, 'No Notes Sub');
        self::assertNotNull($withoutNotes);
        self::assertTrue($withoutNotes->getNotes() === null || $withoutNotes->getNotes() === '');

        // Markup in notes is stored as-is but rendered escaped.
        $payload = '<script>alert("xss")</script>Remember to cancel';
        $crawler = $client->request('GET', '/subscription/new');
        $client->submit($crawler->selectButton('Save')->form([
            'subscription[name]' => 'Xss Notes Sub',
            'subscription[billingCycle]' => BillingCycle::Monthly->value,
            'subscription[amount]' => '10.00',
            'subscription[nextPayment]' => '2026-11-01',
            'subscription[category]' => (string) $category->getId(),
            'subscription[currency]' => 'PLN',
            'subscription[notes]' => $payload,
        ]));
        self::assertResponseRedirects('/dashboard');

        $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert("xss")</script>', $content);
        self::assertStringContainsString('&lt;script&gt;', $content);
    }

    private function createSubscriptionForNewUser(string $email, string $name): Subscription
    {
        $owner = UserFactory::createOne(['email' => $email, 'isVerified' => true]);

        return SubscriptionFactory::createOne(['owner' => $owner, 'name' => $name]);
    }

    private function loginAs(KernelBrowser $client, string $email): void
    {
        $user = $client->getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        $client->loginUser($user);
    }

    private function freshSubscription(KernelBrowser $client, string $id): ?Subscription
    {
        $container = $client->getContainer();
        $container->get('doctrine')->getManager()->clear();

        return $container->get(SubscriptionRepository::class)->find($id);
    }

    private function findSubscriptionByName(KernelBrowser $client, string $name): ?Subscription
    {
        $container = $client->getContainer();
        $container->get('doctrine')->getManager()->clear();

        return $container->get(SubscriptionRepository::class)->findOneBy(['name' => $name]);
    }

    private function setSubscriptionLimit(KernelBrowser $client, string $email, int $limit): void
    {
        $container = $client->getContainer();
        $em = $container->get('doctrine')->getManager();
        $user = $container->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        $managed = $em->find(User::class, $user->getId());
        $managed->setSubscriptionsLimit($limit);
        $em->flush();
        $em->clear();
    }

    private function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        $container = $client->getContainer();
        $session = $client->getSession();
        self::assertNotNull($session);

        // The kernel pops each request off its stack once handled, so outside
        // a request the session-backed CSRF storage has no session to read.
        // Push a scratch request carrying the session instead.
        $requestStack = $container->get('request_stack');
        $requestStack->push($request = new \Symfony\Component\HttpFoundation\Request());
        $request->setSession($session);

        try {
            return $container->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        } finally {
            $requestStack->pop();
            $session->save();
        }
    }
}
