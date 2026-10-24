<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Base class for web tests that need a real database. API v1 tests use
 * App\Tests\Api\V1\ApiV1TestCase instead; both share DatabaseFixtures.
 *
 * Set DATABASE_URL before running (e.g. the CI migrations job's
 * mysql://root:root@127.0.0.1:3306/paysub — the `when@test` dbname suffix
 * turns it into paysub_test). #[ResetDatabase], applied by Foundry's PHPUnit
 * extension, builds the schema from the migrations once per run; each test
 * then runs inside a transaction that DAMA's PHPUnit extension rolls back, so
 * tests start empty (#[SkipDatabaseRollback] opts a test class out).
 *
 * External services are faked, never contacted:
 * - mailer: `null://null` transport plus the test message logger; assert with
 *   MailerAssertionsTrait (getMailerMessage / assertEmailCount).
 * - newsletter subscription: the handler tests run over MockHttpClient and
 *   never contact listmonk; controller tests read the queued
 *   MailingSubscribe off the real Doctrine `async` transport through its
 *   listable receiver.
 * - reCAPTCHA: the HTTP transport is replaced with
 *   App\Tests\Double\FakeRecaptchaRequestMethod (config/packages/test/recaptcha.yaml);
 *   the token `valid-test-token` verifies, any other token fails.
 * - breach-check API (NotCompromisedPassword): the real API is used, so tests
 *   submit high-entropy passwords that cannot appear in the breach corpus.
 */
#[ResetDatabase]
abstract class DatabaseTestCase extends WebTestCase
{
    use DatabaseFixtures;

    protected KernelBrowser $client;

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

    /**
     * Reads the activation-resend CSRF token out of the standalone resend
     * page's form (issue #143), the same place a browser would get it from.
     */
    protected function resendCsrfToken(): string
    {
        $crawler = $this->client->request('GET', '/register/activation');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('form[action="/register/activation/resend"] input[name="_token"]')->attr('value');
        self::assertNotNull($token, 'expected a resend form on the resend activation page');

        return $token;
    }
}
