<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base class for tests that need a real database.
 *
 * Set DATABASE_URL before running (e.g. the CI migrations job's
 * mysql://root:root@127.0.0.1:3306/paysub — the `when@test` dbname suffix
 * turns it into paysub_test). If no server is reachable the test skips
 * cleanly, so the DB-less CI checks job stays green; a merely missing
 * database is created on the fly.
 */
abstract class DatabaseTestCase extends WebTestCase
{
    protected EntityManagerInterface $em;

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        // Boots the kernel exactly once: createClient() refuses a pre-booted kernel.
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();

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
}
