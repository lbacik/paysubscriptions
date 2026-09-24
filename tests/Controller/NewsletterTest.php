<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Message\MailingSubscribe;
use App\Tests\DatabaseTestCase;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Newsletter signup validates the address and the CSRF token, then queues a
 * MailingSubscribe message on the `newsletter` transport. In the test
 * environment that transport is `in-memory://` (see the `when@test` override
 * in config/packages/messenger.yaml), so no broker is required and the test
 * inspects exactly what would have been published.
 */
final class NewsletterTest extends DatabaseTestCase
{
    private const PROJECT_UUID = '00000000-0000-0000-0000-000000000000';
    private const ROUTING_KEY = 'test-routing-key';

    /** @var array<string, array{mixed, mixed}> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The service binds %env(JSON_HUB_PROJECT)% and
        // %mailingProviderRoutingKey% lazily on subscribe(); real env vars win
        // over .env files, so pinning them here keeps the test hermetic.
        // Originals are restored in tearDown() so nothing leaks into later tests.
        $this->pinEnv('JSON_HUB_PROJECT', self::PROJECT_UUID);
        $this->pinEnv('MAILING_PROVIDER_ROUTING_KEY', self::ROUTING_KEY);
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => [$server, $env]) {
            if (null === $server) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }

            if (null === $env) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
        }
        $this->envBackup = [];

        parent::tearDown();
    }

    public function testValidSignupQueuesMailingSubscribe(): void
    {
        $token = $this->newsletterCsrfToken();

        $this->client->request(
            'POST',
            '/newsletter/subscribe',
            ['email' => 'reader@example.com', '_token' => $token],
            [],
            ['HTTP_REFERER' => 'http://localhost/pricing'],
        );

        self::assertResponseRedirects('http://localhost/pricing');

        $sent = $this->newsletterTransport()->getSent();
        self::assertCount(1, $sent);

        $envelope = $sent[0];
        $message = $envelope->getMessage();
        self::assertInstanceOf(MailingSubscribe::class, $message);
        self::assertSame('reader@example.com', $message->email);
        self::assertSame(self::PROJECT_UUID, $message->entityId);

        $stamp = $envelope->last(AmqpStamp::class);
        self::assertNotNull($stamp, 'expected a routing stamp for the mailing provider');
        self::assertSame(self::ROUTING_KEY, $stamp->getRoutingKey());
    }

    public function testMissingCsrfTokenIsRejectedAndQueuesNothing(): void
    {
        $this->client->request(
            'POST',
            '/newsletter/subscribe',
            ['email' => 'reader@example.com'],
            [],
            ['HTTP_REFERER' => 'http://localhost/pricing'],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->newsletterTransport()->getSent());
    }

    public function testForgedCsrfTokenIsRejectedAndQueuesNothing(): void
    {
        $this->client->request(
            'POST',
            '/newsletter/subscribe',
            ['email' => 'reader@example.com', '_token' => 'forged-token'],
            [],
            ['HTTP_REFERER' => 'http://localhost/pricing'],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->newsletterTransport()->getSent());
    }

    public function testSubscribeRouteRejectsGet(): void
    {
        $this->client->request('GET', '/newsletter/subscribe');

        self::assertResponseStatusCodeSame(405);
    }

    private function newsletterTransport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.newsletter');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function pinEnv(string $key, string $value): void
    {
        $this->envBackup[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
    }

    /**
     * Reads the token out of the footer's signup form, the same place a
     * browser would get it from.
     */
    private function newsletterCsrfToken(): string
    {
        $crawler = $this->client->request('GET', '/pricing');
        self::assertResponseIsSuccessful();

        return $crawler->filter('form[name="newsletter"] input[name="_token"]')->attr('value');
    }
}
