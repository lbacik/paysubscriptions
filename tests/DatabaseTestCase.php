<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Limits;
use App\Entity\ResetPasswordRequest;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base class for behavioral tests that need a database.
 *
 * Each test gets a freshly created schema (drop + create via SchemaTool), so
 * tests never leak rows into each other and never depend on execution order.
 * The schema is derived from the entity metadata, which means these tests
 * prove the mappings work but do NOT prove the migrations produce the same
 * schema - that is the job of the `migrations` CI job, which replays the
 * production migration command and loads fixtures on top.
 *
 * The suite talks to the database named by DATABASE_URL with the `when@test`
 * `_test` suffix appended (e.g. `paysub` becomes `paysub_test`), so it can
 * never touch the dev database. The database itself is created on demand if
 * it does not exist yet.
 *
 * External services are faked, never contacted:
 * - mailer: `null://null` transport plus the test message logger; assert with
 *   MailerAssertionsTrait (getMailerMessage / assertEmailCount).
 * - messenger `newsletter` transport: overridden to `in-memory://` in
 *   config/packages/messenger.yaml (`when@test`); assert via the
 *   `messenger.transport.newsletter` service's getSent().
 * - reCAPTCHA: replaced per-test with App\Tests\Double\FakeReCaptcha through
 *   the test container.
 * - breach-check API (NotCompromisedPassword): the real API is used, so tests
 *   submit high-entropy passwords that cannot appear in the breach corpus.
 */
abstract class DatabaseTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        self::ensureTestDatabaseExists($this->em);
        $this->resetSchema();
    }

    /**
     * @return list<class-string>
     */
    protected static function managedEntities(): array
    {
        return [
            User::class,
            Subscription::class,
            Limits::class,
            ResetPasswordRequest::class,
        ];
    }

    private function resetSchema(): void
    {
        $metadata = array_map(
            fn(string $class) => $this->em->getClassMetadata($class),
            static::managedEntities(),
        );

        $tool = new SchemaTool($this->em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    private static function ensureTestDatabaseExists(EntityManagerInterface $em): void
    {
        $connection = $em->getConnection();
        $params = $connection->getParams();

        if (!isset($params['dbname'])) {
            return;
        }

        $dbname = $params['dbname'];
        unset($params['dbname'], $params['path'], $params['url']);

        // sqlite creates the file on connect; nothing to provision.
        if (isset($params['driver']) && str_contains((string) $params['driver'], 'sqlite')) {
            return;
        }

        $admin = DriverManager::getConnection($params, $connection->getConfiguration());
        $quoted = str_contains((string) ($params['driver'] ?? ''), 'pgsql')
            ? '"'.$dbname.'"'
            : '`'.$dbname.'`';
        $admin->executeStatement(sprintf('CREATE DATABASE IF NOT EXISTS %s', $quoted));
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
        ?float $monthly = 15.99,
        ?float $yearly = null,
        ?\DateTimeInterface $firstPayment = null,
    ): Subscription {
        $subscription = (new Subscription())
            ->setName($name)
            ->setFirstPayment($firstPayment ?? new \DateTime('2024-01-15'))
            ->setMonthly($monthly)
            ->setYearly($yearly);
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
