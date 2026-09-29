<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\DatabaseTestCase;
use App\Tests\RateLimitTestHelper;

/**
 * Web-login brute-force protection (issue #143).
 *
 * After LOGIN_MAX_ATTEMPTS failed logins for the same account inside the
 * window, further attempts — even with the correct password — bounce back to
 * the login page with a lockout message instead of authenticating. The email
 * and the source IP are unique per run so limiter state can never leak
 * between runs; the suite default stays unthrottled (see .env.test).
 */
final class LoginThrottlingTest extends DatabaseTestCase
{
    use RateLimitTestHelper;

    private const PASSWORD = 'Fixture-Password-1';

    private string $email;
    private string $ip;

    protected function setUp(): void
    {
        // Opt in: three strikes. Pinned before the kernel boots so the login
        // limiter factories resolve the small bursts.
        $this->pinRateLimitEnv(['LOGIN_MAX_ATTEMPTS' => '3', 'LOGIN_MAX_ATTEMPTS_IP' => '15']);
        parent::setUp();

        $this->email = sprintf('throttle-%s@example.com', bin2hex(random_bytes(4)));
        $this->ip = sprintf('10.%d.%d.%d', random_int(0, 254), random_int(0, 254), random_int(1, 254));
    }

    protected function tearDown(): void
    {
        $this->restoreRateLimitEnv();

        parent::tearDown();
    }

    public function testFailedLoginsLockOutEvenWithCorrectPassword(): void
    {
        $this->createUser($this->email, self::PASSWORD, true);

        // Three failed attempts: each one bounces back to the login page.
        for ($i = 0; $i < 3; ++$i) {
            $this->loginFromTestIp($this->email, 'Wrong-Password-2');
            self::assertResponseRedirects('/login');
        }

        // The fourth attempt carries the correct password but the account is
        // throttled: no dashboard, and the visitor is told to wait.
        $this->loginFromTestIp($this->email, self::PASSWORD);
        self::assertResponseRedirects('/login');

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Too many failed login attempts', $crawler->text(null, true));
    }

    public function testLockoutIsPerAccountNotPerIp(): void
    {
        $this->createUser($this->email, self::PASSWORD, true);
        $otherEmail = sprintf('throttle-other-%s@example.com', bin2hex(random_bytes(4)));
        $this->createUser($otherEmail, self::PASSWORD, true);

        for ($i = 0; $i < 3; ++$i) {
            $this->loginFromTestIp($this->email, 'Wrong-Password-2');
            self::assertResponseRedirects('/login');
        }

        // The throttled account stays out…
        $this->loginFromTestIp($this->email, self::PASSWORD);
        self::assertResponseRedirects('/login');

        // …while a different account from the same IP logs in normally.
        $this->loginFromTestIp($otherEmail, self::PASSWORD);
        self::assertResponseRedirects('/dashboard');
    }

    private function loginFromTestIp(string $email, string $password): void
    {
        $crawler = $this->client->request('GET', '/login', [], [], ['REMOTE_ADDR' => $this->ip]);
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->request('POST', '/login', [
            'email' => $email,
            'password' => $password,
            '_csrf_token' => $token,
        ], [], ['REMOTE_ADDR' => $this->ip]);
    }
}
