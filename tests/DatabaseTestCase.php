<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use App\Service\ExpenseCategoryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Base class for tests that need a real database.
 *
 * Set DATABASE_URL before running (e.g. the CI migrations job's
 * mysql://root:root@127.0.0.1:3306/paysub — the `when@test` dbname suffix
 * turns it into paysub_test). Foundry's ResetDatabase builds the schema from
 * the migrations once per run; each test then runs inside a transaction that
 * App\Tests\DatabaseTransactionExtension rolls back, so tests start empty.
 *
 * External services are faked, never contacted:
 * - mailer: `null://null` transport plus the test message logger; assert with
 *   MailerAssertionsTrait (getMailerMessage / assertEmailCount).
 * - messenger `newsletter` transport: overridden to `in-memory://` in
 *   config/packages/messenger.yaml (`when@test`); assert via the
 *   `messenger.transport.newsletter` service's getSent().
 * - reCAPTCHA: the HTTP transport is replaced with
 *   App\Tests\Double\FakeRecaptchaRequestMethod (config/packages/test/recaptcha.yaml);
 *   the token `valid-test-token` verifies, any other token fails.
 * - breach-check API (NotCompromisedPassword): the real API is used, so tests
 *   submit high-entropy passwords that cannot appear in the breach corpus.
 */
abstract class DatabaseTestCase extends WebTestCase
{
    use ResetDatabase;

    protected KernelBrowser $client;

    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        // Boots the kernel exactly once: createClient() refuses a pre-booted kernel.
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->close();
        }

        parent::tearDown();
    }

    protected function freshEm(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function createUser(string $email, string $plainPassword = 'Fixture-Password-1', bool $verified = true): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = (new User())
            ->setEmail($email)
            ->setVerified($verified);
        $user->setPassword($hasher->hashPassword($user, $plainPassword));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function createSubscription(
        User $owner,
        string $name = 'Netflix',
        BillingCycle $billingCycle = BillingCycle::Monthly,
        float $amount = 15.99,
        ?\DateTimeInterface $nextPayment = null,
    ): Subscription {
        // Resolved before building the Subscription: ensureDefaultCategory()
        // flushes internally, which would otherwise trip Doctrine's cascade
        // check on the not-yet-persisted Subscription reachable through
        // User#subscriptions.
        $category = static::getContainer()->get(ExpenseCategoryService::class)->ensureDefaultCategory($owner);

        $subscription = (new Subscription())
            ->setName($name)
            ->setBillingCycle($billingCycle)
            ->setAmount($amount)
            ->setNextPayment($nextPayment ?? new \DateTime('2024-01-15'))
            ->setCategory($category);
        // Keep both sides of the association in sync: the subscription-limit
        // check counts the owner's in-memory collection, so setOwner() alone
        // would leave it stale within the same entity-manager lifecycle.
        $owner->addSubscription($subscription);

        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }

    protected function freshUser(string $email): ?User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    protected function login(string $email, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->request('POST', '/login', [
            'email' => $email,
            'password' => $password,
            '_csrf_token' => $token,
        ]);
    }
}
