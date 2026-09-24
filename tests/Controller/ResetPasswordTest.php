<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ResetPasswordRequest;
use App\Tests\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Password reset never reveals whether an address is registered, delivers a
 * single-use token by email, and only the valid token allows setting a new
 * password.
 */
final class ResetPasswordTest extends DatabaseTestCase
{
    use MailerAssertionsTrait;

    private const PASSWORD = 'Fixture-Password-1';
    private const NEW_PASSWORD = 'Br4nd-New!Password#2026-qW8zxCvB';

    public function testRequestPageRenders(): void
    {
        $this->client->request('GET', '/reset-password');

        self::assertResponseIsSuccessful();
    }

    public function testRequestForKnownEmailRedirectsAndSendsEmail(): void
    {
        $this->createUser('alice@example.com', self::PASSWORD, true);

        $crawler = $this->client->request('GET', '/reset-password');
        $form = $crawler->selectButton('Send password reset email')->form([
            'reset_password_request_form[email]' => 'alice@example.com',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/reset-password/check-email');

        self::assertEmailCount(1);
        $email = $this->getMailerMessage(0);
        self::assertEmailSubjectContains($email, 'Your password reset request');
        self::assertEmailAddressContains($email, 'To', 'alice@example.com');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testRequestForUnknownEmailRedirectsIdenticallyButSendsNothing(): void
    {
        $crawler = $this->client->request('GET', '/reset-password');
        $form = $crawler->selectButton('Send password reset email')->form([
            'reset_password_request_form[email]' => 'ghost@example.com',
        ]);
        $this->client->submit($form);

        // Same outcome as for a known address: no account enumeration.
        self::assertResponseRedirects('/reset-password/check-email');
        self::assertEmailCount(0);
        self::assertCount(0, $this->em->getRepository(ResetPasswordRequest::class)->findAll());
    }

    public function testRequestWithBlankEmailStaysOnTheForm(): void
    {
        $crawler = $this->client->request('GET', '/reset-password');
        $form = $crawler->selectButton('Send password reset email')->form([
            'reset_password_request_form[email]' => '',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);
    }

    public function testValidTokenAllowsSettingANewPassword(): void
    {
        $user = $this->createUser('alice@example.com', self::PASSWORD, true);

        /** @var ResetPasswordHelperInterface $helper */
        $helper = static::getContainer()->get(ResetPasswordHelperInterface::class);
        $token = $helper->generateResetToken($user)->getToken();

        // Visiting the emailed link stores the token in the session and
        // removes it from the URL.
        $this->client->request('GET', '/reset-password/reset/'.$token);
        self::assertResponseRedirects('/reset-password/reset');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Reset password')->form([
            'change_password_form[plainPassword][first]' => self::NEW_PASSWORD,
            'change_password_form[plainPassword][second]' => self::NEW_PASSWORD,
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/login');

        // The old password is dead, the new one authenticates.
        $this->login('alice@example.com', self::PASSWORD);
        self::assertResponseRedirects('/login');

        $this->client->restart();
        $this->login('alice@example.com', self::NEW_PASSWORD);
        self::assertResponseRedirects('/dashboard');
    }

    public function testInvalidTokenBouncesBackToTheRequestPage(): void
    {
        $this->createUser('alice@example.com', self::PASSWORD, true);

        $this->client->request('GET', '/reset-password/reset/this-token-is-bogus');
        self::assertResponseRedirects('/reset-password/reset');
        $this->client->followRedirect();
        self::assertResponseRedirects('/reset-password');

        // The password is untouched: the old one still authenticates.
        $this->client->restart();
        $this->login('alice@example.com', self::PASSWORD);
        self::assertResponseRedirects('/dashboard');
    }

    public function testResetPageWithoutATokenIsNotFound(): void
    {
        $this->client->request('GET', '/reset-password/reset');

        self::assertResponseStatusCodeSame(404);
    }
}
