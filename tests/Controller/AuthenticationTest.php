<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\DatabaseTestCase;

final class AuthenticationTest extends DatabaseTestCase
{
    private const PASSWORD = 'Fixture-Password-1';

    public function testVerifiedUserCanLogInAndReachDashboard(): void
    {
        $this->createUser('alice@example.com', self::PASSWORD, true);

        $this->login('alice@example.com', self::PASSWORD);

        self::assertResponseRedirects('/dashboard');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        // A fresh account holds no subscriptions, so both totals render 0.00:
        // this proves the dashboard itself rendered, not just any page.
        self::assertStringContainsString('0.00', $crawler->text(null, true));
    }

    public function testUnverifiedUserCannotLogIn(): void
    {
        $this->createUser('pending@example.com', self::PASSWORD, false);

        $this->login('pending@example.com', self::PASSWORD);

        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('not active', $crawler->text(null, true));
    }

    public function testUnverifiedLoginPrefillsResendForm(): void
    {
        $this->createUser('pending@example.com', self::PASSWORD, false);

        $this->login('pending@example.com', self::PASSWORD);

        $crawler = $this->client->followRedirect();
        // The alert carries no link or HTML; recovery is the page's own
        // POST resend form, prefilled with the attempted address.
        self::assertCount(0, $crawler->filter('div[role="alert"] a'));
        self::assertCount(1, $crawler->filter('form[action="/register/activation/resend"]'));
        self::assertSame('pending@example.com', $crawler->filter('#resendEmail')->attr('value'));
    }

    public function testWrongPasswordHidesVerificationState(): void
    {
        $this->createUser('verified@example.com', self::PASSWORD, true);
        $this->createUser('pending@example.com', self::PASSWORD, false);

        $verifiedBody = $this->failedLoginBody('verified@example.com');
        $unverifiedBody = $this->failedLoginBody('pending@example.com');
        $unknownBody = $this->failedLoginBody('ghost@example.com');

        // A wrong password answers identically whether the account is
        // verified, unverified, or missing entirely: no "not active" hint
        // that would disclose an unverified registration (the page's resend
        // form is unconditional, so its presence reveals nothing).
        foreach ([$verifiedBody, $unverifiedBody, $unknownBody] as $body) {
            self::assertStringNotContainsString('not active', strip_tags($body));
            self::assertStringContainsString('Invalid credentials', strip_tags($body));
        }
        self::assertSame(
            strip_tags($verifiedBody),
            strip_tags($unverifiedBody),
            'verified and unverified responses must be indistinguishable'
        );
    }

    public function testLoginErrorIsRenderedEscaped(): void
    {
        $this->login('ghost@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $html = (string) $this->client->getResponse()->getContent();
        // The alert carries the translated message as text: no markup from
        // the error itself may survive unescaped into the page.
        self::assertStringNotContainsString('<a class="underline" href=', $html);
    }

    public function testLoginWithWrongPasswordFails(): void
    {
        $this->createUser('alice@example.com', self::PASSWORD, true);

        $this->login('alice@example.com', 'Wrong-Password-2');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testLoginWithUnknownEmailFails(): void
    {
        $this->login('ghost@example.com', self::PASSWORD);

        self::assertResponseRedirects('/login');
    }

    public function testAuthenticatedUserVisitingLoginIsSentToDashboard(): void
    {
        $this->createUser('alice@example.com', self::PASSWORD, true);
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $this->client->request('GET', '/login');

        self::assertResponseRedirects('/dashboard');
    }

    public function testUserCanLogOut(): void
    {
        $this->createUser('alice@example.com', self::PASSWORD, true);
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $this->logout();

        // The session no longer authenticates: the dashboard bounces to login.
        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testLogoutWithoutCsrfTokenIsRejectedAndKeepsSession(): void
    {
        $this->createUser('alice@example.com', self::PASSWORD, true);
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        // A plain GET — the old logout — must not log anyone out.
        $this->client->request('GET', '/logout');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
    }

    public function testLogoutWithInvalidCsrfTokenIsRejectedAndKeepsSession(): void
    {
        $this->createUser('alice@example.com', self::PASSWORD, true);
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $this->client->request('POST', '/logout', ['_csrf_token' => 'forged-token']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
    }

    public function testLogoutFormsCarryCsrfTokens(): void
    {
        $this->createUser('alice@example.com', self::PASSWORD, true);
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/dashboard');

        $forms = $crawler->filter('form[action="/logout"]');
        self::assertGreaterThanOrEqual(1, $forms->count(), 'the UI must offer a POST logout form');
        $forms->each(static function ($form): void {
            self::assertSame('post', strtolower((string) $form->attr('method')));
            self::assertNotEmpty($form->filter('input[name="_csrf_token"]')->attr('value'));
        });
    }

    /**
     * Submits the wrong password and returns the raw rendered login page.
     */
    private function failedLoginBody(string $email): string
    {
        $this->login($email, 'Wrong-Password-2');
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();

        return (string) $this->client->getResponse()->getContent();
    }

    private function logout(): void
    {
        $crawler = $this->client->request('GET', '/dashboard');
        $token = $crawler->filter('form[action="/logout"] input[name="_csrf_token"]')->attr('value');
        self::assertNotEmpty($token);

        $this->client->request('POST', '/logout', ['_csrf_token' => $token]);
        $this->client->followRedirect();
    }
}
