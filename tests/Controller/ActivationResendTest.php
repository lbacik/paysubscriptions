<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\DatabaseTestCase;
use App\Tests\RateLimitTestHelper;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activation-email resend hardening (issue #143).
 *
 * The endpoint accepts only POST with a valid CSRF token, answers verified
 * and unknown addresses exactly like unverified ones (same redirect, same
 * flash, but no email leaves the server), and is throttled per recipient
 * address and per IP.
 */
final class ActivationResendTest extends DatabaseTestCase
{
    use MailerAssertionsTrait;
    use RateLimitTestHelper;

    private const PASSWORD = 'Fixture-Password-1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetLimiter('limiter.activation_resend_email', 'resend-unverified@example.com');
        $this->resetLimiter('limiter.activation_resend_email', 'resend-verified@example.com');
        $this->resetLimiter('limiter.activation_resend_email', 'resend-unknown@example.com');
        $this->resetLimiter('limiter.activation_resend_email', 'resend-limited@example.com');
        $this->resetLimiter('limiter.activation_resend_ip', '127.0.0.1');
    }

    protected function tearDown(): void
    {
        $this->restoreRateLimitEnv();

        parent::tearDown();
    }

    public function testGetIsNotAllowed(): void
    {
        $this->client->request('GET', '/register/activation/resend', ['email' => 'any@example.com']);

        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testPostWithoutCsrfTokenIsRejectedAndSendsNothing(): void
    {
        $this->createUser('resend-unverified@example.com', self::PASSWORD, false);

        $this->client->request('POST', '/register/activation/resend', [
            'email' => 'resend-unverified@example.com',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertEmailCount(0);
    }

    public function testPostWithForgedCsrfTokenIsRejectedAndSendsNothing(): void
    {
        $this->createUser('resend-unverified@example.com', self::PASSWORD, false);

        $this->client->request('POST', '/register/activation/resend', [
            'email' => 'resend-unverified@example.com',
            '_token' => 'forged-token',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertEmailCount(0);
    }

    public function testResendForUnverifiedAccountSendsEmail(): void
    {
        $this->createUser('resend-unverified@example.com', self::PASSWORD, false);

        $this->client->request('POST', '/register/activation/resend', [
            'email' => 'resend-unverified@example.com',
            '_token' => $this->resendCsrfToken(),
        ]);

        self::assertResponseRedirects('/login');
        self::assertEmailCount(1);
    }

    public function testResendForVerifiedAccountSendsNothingButLooksIdentical(): void
    {
        $this->createUser('resend-verified@example.com', self::PASSWORD, true);

        $this->client->request('POST', '/register/activation/resend', [
            'email' => 'resend-verified@example.com',
            '_token' => $this->resendCsrfToken(),
        ]);

        // Same outward response as an unverified address…
        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString(
            'Activation email has been sent',
            $crawler->text(null, true)
        );

        // …but nothing leaves the server: re-sending to a verified account
        // would confirm the address is registered and waste a delivery.
        self::assertEmailCount(0);
    }

    public function testResendForUnknownAccountSendsNothingButLooksIdentical(): void
    {
        $this->client->request('POST', '/register/activation/resend', [
            'email' => 'resend-unknown@example.com',
            '_token' => $this->resendCsrfToken(),
        ]);

        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString(
            'Activation email has been sent',
            $crawler->text(null, true)
        );
        self::assertEmailCount(0);
    }

    public function testUnverifiedLoginOffersWorkingResendForm(): void
    {
        $this->createUser('resend-unverified@example.com', self::PASSWORD, false);

        $this->login('resend-unverified@example.com', self::PASSWORD);
        self::assertResponseRedirects('/login');

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('not active', $crawler->text(null, true));

        // The block message carries an inline POST form (not a link) whose
        // token the endpoint accepts: submitting it queues the email.
        $form = $crawler->selectButton('Send activation email again')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/login');
        self::assertEmailCount(1);
    }

    public function testResendIsLimitedPerEmail(): void
    {
        $this->pinRateLimitEnv(['RATE_LIMIT_RESEND_EMAIL' => '2', 'RATE_LIMIT_RESEND_IP' => '100000']);
        $this->createUser('resend-limited@example.com', self::PASSWORD, false);
        $token = $this->resendCsrfToken();

        $this->postResend('resend-limited@example.com', $token);
        self::assertResponseRedirects('/login');
        $this->postResend('resend-limited@example.com', $token);
        self::assertResponseRedirects('/login');

        $this->postResend('resend-limited@example.com', $token);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testResendIsLimitedPerIp(): void
    {
        $this->pinRateLimitEnv(['RATE_LIMIT_RESEND_EMAIL' => '100000', 'RATE_LIMIT_RESEND_IP' => '2']);
        $this->createUser('resend-unverified@example.com', self::PASSWORD, false);
        $token = $this->resendCsrfToken();

        $this->postResend('resend-unverified@example.com', $token);
        self::assertResponseRedirects('/login');
        $this->postResend('resend-unknown@example.com', $token);
        self::assertResponseRedirects('/login');

        $this->postResend('resend-unverified@example.com', $token);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    private function postResend(string $email, string $token): void
    {
        $this->client->request('POST', '/register/activation/resend', [
            'email' => $email,
            '_token' => $token,
        ]);
    }

    /**
     * Reads the token out of the login page's resend form, the same place a
     * browser would get it from.
     */
    private function resendCsrfToken(): string
    {
        $crawler = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('form[action="/register/activation/resend"] input[name="_token"]')->attr('value');
        self::assertNotNull($token, 'expected a resend form on the login page');

        return $token;
    }
}
