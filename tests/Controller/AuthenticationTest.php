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

        $this->client->request('GET', '/logout');
        $this->client->followRedirect();

        // The session no longer authenticates: the dashboard bounces to login.
        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }
}
