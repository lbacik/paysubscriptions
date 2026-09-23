<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base class for tests that need a real database.
 *
 * Set DATABASE_URL before running (e.g. the CI migrations job's
 * mysql://root:root@127.0.0.1:3306/paysub — the `when@test` dbname suffix
 * turns it into paysub_test). If no server is reachable the test skips
 * cleanly, so the DB-less CI checks job stays green; a merely missing
 * database is created on the fly, and a missing schema from the entity
 * mappings.
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

        // Boots the kernel exactly once: createClient() refuses a pre-booted kernel.
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        try {
            $this->em->getConnection()->fetchOne('SELECT 1');
        } catch (\Throwable $e) {
            if ($this->isUnknownDatabase($e)) {
                $this->createDatabase();
                $this->em->getConnection()->fetchOne('SELECT 1');
            } else {
                self::markTestSkipped('No database available: ' . $e->getMessage());
            }
        }

        $this->ensureSchema();
        $this->cleanTables();
        $this->ensureMessengerTable();
        $this->cleanMessengerTable();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->close();
        }

        parent::tearDown();
    }

    protected function recreateSchema(): void
    {
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function dropSchema(): void
    {
        (new SchemaTool($this->em))->dropSchema($this->em->getMetadataFactory()->getAllMetadata());
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
        $subscription = (new Subscription())
            ->setName($name)
            ->setBillingCycle($billingCycle)
            ->setAmount($amount)
            ->setNextPayment($nextPayment ?? new \DateTime('2024-01-15'));
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

    private function isUnknownDatabase(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Unknown database')
            || str_contains($message, 'unknown database');
    }

    private function createDatabase(): void
    {
        $connection = $this->em->getConnection();
        $params = $connection->getParams();
        $database = $params['dbname'] ?? $params['path'] ?? null;

        if (null === $database) {
            throw new \LogicException('Cannot determine the test database name.');
        }

        unset($params['dbname'], $params['path'], $params['url'], $params['driverOptions']);

        $serverConnection = new \Doctrine\DBAL\Connection($params, $connection->getDriver(), $connection->getConfiguration());
        $serverConnection->executeStatement(sprintf(
            'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $serverConnection->quoteIdentifier($database)
        ));
        $serverConnection->close();
    }

    private function ensureSchema(): void
    {
        $connection = $this->em->getConnection();

        try {
            $tables = $connection->createSchemaManager()->listTableNames();
        } catch (\Throwable) {
            $tables = [];
        }

        if (!\in_array('user', $tables, true)) {
            $metadata = $this->em->getMetadataFactory()->getAllMetadata();
            (new SchemaTool($this->em))->createSchema($metadata);
        }
    }

    private function cleanTables(): void
    {
        // Foreign-key-safe order: children before parents.
        foreach (['App\Entity\ResetPasswordRequest', 'App\Entity\Subscription', 'App\Entity\Limits', 'App\Entity\User'] as $class) {
            $this->em->createQuery(sprintf('DELETE FROM %s e', $class))->execute();
        }

        if (class_exists('App\Entity\ExpenseCategory')) {
            $this->em->createQuery('DELETE FROM App\Entity\ExpenseCategory e')->execute();
        }

        $this->em->clear();
    }

    private function ensureMessengerTable(): void
    {
        // The doctrine messenger transport (auto_setup=0) never creates its
        // table in the test schema; create the production shape by hand.
        $this->em->getConnection()->executeStatement(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS messenger_messages (
                    id BIGINT AUTO_INCREMENT NOT NULL,
                    body LONGTEXT NOT NULL,
                    headers LONGTEXT NOT NULL,
                    queue_name VARCHAR(190) NOT NULL,
                    created_at DATETIME NOT NULL,
                    available_at DATETIME NOT NULL,
                    delivered_at DATETIME DEFAULT NULL,
                    INDEX IDX_75EA56E0FB7336F0 (queue_name),
                    INDEX IDX_75EA56E0E3BD61CE (available_at),
                    INDEX IDX_75EA56E016BA31DB (delivered_at),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
                SQL
        );
    }

    private function cleanMessengerTable(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM messenger_messages');
    }
}
