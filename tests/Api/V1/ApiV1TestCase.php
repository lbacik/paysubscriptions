<?php

declare(strict_types=1);

namespace App\Tests\Api\V1;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\User;
use App\Tests\DatabaseFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Base class for API v1 tests: API Platform's test client and assertions
 * over the real database, with the shared fixture helpers and in-process
 * token minting.
 *
 * Database lifecycle is the same as App\Tests\DatabaseTestCase: the schema is
 * built from the migrations once per run and every test is rolled back.
 * Requests default to `Accept: application/hal+json`, the API's default
 * format; format tests override it per request. The approved CLI client is
 * registered for every test, so requestAs() only needs a User.
 */
#[ResetDatabase]
abstract class ApiV1TestCase extends ApiTestCase
{
    use DatabaseFixtures;
    use MintsAccessTokens;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient(defaultOptions: ['headers' => ['accept' => 'application/hal+json']]);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->registerPublicClient();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->close();
        }

        parent::tearDown();
    }

    /**
     * Sends a request with a valid `api:full` bearer token for $user.
     *
     * @param array<string, mixed> $options Symfony HttpClient request options
     */
    protected function requestAs(User $user, string $method, string $url, array $options = []): ResponseInterface
    {
        $options['auth_bearer'] = $this->craftToken($user->getUserIdentifier());

        return $this->client->request($method, $url, $options);
    }
}
