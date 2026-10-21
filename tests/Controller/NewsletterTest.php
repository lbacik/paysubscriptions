<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Message\MailingSubscribe;
use App\Tests\DatabaseTestCase;
use App\Tests\Double\FakeRecaptchaRequestMethod;
use App\Tests\RateLimitTestHelper;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

/**
 * Newsletter signup hardening (issue #143).
 *
 * Signup validates the address and the CSRF token, verifies an invisible
 * reCAPTCHA (the contact-form pattern: enforced whenever keys are
 * configured, which they always are under test), throttles per source IP,
 * then queues a MailingSubscribe message on the `async` transport. The
 * worker's MailingSubscribeHandler subscribes the address through the
 * self-hosted listmonk API, and listmonk sends its own opt-in mail. Under
 * test `async` stays the real Doctrine transport (no in-memory stand-in), so
 * the test reads exactly what the worker would consume.
 *
 * Success and captcha failure both redirect to the homepage — never to the
 * `Referer` header, which is both an open-redirect vector and a 500 when the
 * header is missing.
 */
final class NewsletterTest extends DatabaseTestCase
{
    use RateLimitTestHelper;

    protected function setUp(): void
    {
        parent::setUp();

        // Limiter state survives in the cache across runs; the IP key below
        // is fixed, so every test starts from a full burst.
        $this->resetLimiter('limiter.newsletter_ip', '127.0.0.1');
    }

    protected function tearDown(): void
    {
        $this->restoreRateLimitEnv();

        parent::tearDown();
    }

    public function testValidSignupQueuesMailingSubscribe(): void
    {
        $token = $this->newsletterCsrfToken();

        $this->client->request(
            'POST',
            '/newsletter/subscribe',
            [
                'email' => 'reader@example.com',
                '_token' => $token,
                'g-recaptcha-response' => FakeRecaptchaRequestMethod::VALID_TOKEN,
            ],
            [],
            ['HTTP_REFERER' => 'http://localhost/pricing'],
        );

        // The Referer is ignored: success always lands on the homepage.
        self::assertResponseRedirects('/');

        $sent = $this->queuedSignups();
        self::assertCount(1, $sent);

        $envelope = $sent[0];
        $message = $envelope->getMessage();
        self::assertInstanceOf(MailingSubscribe::class, $message);
        self::assertSame(['email' => 'reader@example.com'], get_object_vars($message));
    }

    public function testMissingRefererRedirectsHomeWithout500(): void
    {
        $token = $this->newsletterCsrfToken();

        // No Referer header at all: used to crash with a 500.
        $this->client->request(
            'POST',
            '/newsletter/subscribe',
            [
                'email' => 'reader@example.com',
                '_token' => $token,
                'g-recaptcha-response' => FakeRecaptchaRequestMethod::VALID_TOKEN,
            ],
        );

        self::assertResponseRedirects('/');
        self::assertCount(1, $this->queuedSignups());
    }

    public function testFailedCaptchaRedirectsHomeAndQueuesNothing(): void
    {
        $token = $this->newsletterCsrfToken();

        $this->client->request(
            'POST',
            '/newsletter/subscribe',
            [
                'email' => 'reader@example.com',
                '_token' => $token,
                'g-recaptcha-response' => 'bogus-token',
            ],
            [],
            ['HTTP_REFERER' => 'http://localhost/pricing'],
        );

        // Rejected like a valid submission's evil twin: same fixed redirect,
        // but nothing reaches the newsletter queue.
        self::assertResponseRedirects('/');
        self::assertCount(0, $this->queuedSignups());
    }

    public function testMissingCaptchaQueuesNothing(): void
    {
        $token = $this->newsletterCsrfToken();

        $this->client->request(
            'POST',
            '/newsletter/subscribe',
            ['email' => 'reader@example.com', '_token' => $token],
            [],
            ['HTTP_REFERER' => 'http://localhost/pricing'],
        );

        self::assertResponseRedirects('/');
        self::assertCount(0, $this->queuedSignups());
    }

    public function testSubscribeIsLimitedPerIp(): void
    {
        // Opt in: two signups per IP per hour.
        $this->pinRateLimitEnv(['RATE_LIMIT_NEWSLETTER_IP' => '2']);
        $token = $this->newsletterCsrfToken();

        // Unlike the old in-memory stand-in, the Doctrine transport keeps
        // every queued row for the whole test: each accepted signup adds
        // exactly one message…
        $this->postSignup('first@example.com', $token);
        self::assertResponseRedirects('/');
        self::assertCount(1, $this->queuedSignups());

        $this->postSignup('second@example.com', $token);
        self::assertResponseRedirects('/');
        self::assertCount(2, $this->queuedSignups());

        // …and the throttled third adds nothing.
        $this->postSignup('third@example.com', $token);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertCount(2, $this->queuedSignups());
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
        self::assertCount(0, $this->queuedSignups());
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
        self::assertCount(0, $this->queuedSignups());
    }

    public function testSubscribeRouteRejectsGet(): void
    {
        $this->client->request('GET', '/newsletter/subscribe');

        self::assertResponseStatusCodeSame(405);
    }

    private function postSignup(string $email, string $token): void
    {
        $this->client->request(
            'POST',
            '/newsletter/subscribe',
            [
                'email' => $email,
                '_token' => $token,
                'g-recaptcha-response' => FakeRecaptchaRequestMethod::VALID_TOKEN,
            ],
        );
    }

    /**
     * Everything the worker would consume: the Doctrine `async` transport
     * contents, read through its listable receiver. Each request boots a
     * fresh kernel, so this always observes the transport of the request that
     * just ran — and, with the per-test transaction rollback, every test
     * starts from an empty queue.
     *
     * @return list<Envelope>
     */
    private function queuedSignups(): array
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(ListableReceiverInterface::class, $transport);

        return iterator_to_array($transport->all());
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
