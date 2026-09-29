<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/**
 * Registration creates an unverified account and queues a confirmation email;
 * the account only becomes usable after the signed verification link is
 * visited. Covers the failure paths (duplicate email, weak password,
 * missing terms, tampered link) as well as the resend endpoint.
 */
final class RegistrationTest extends DatabaseTestCase
{
    use MailerAssertionsTrait;

    // High-entropy: passes Length(12+), PasswordStrength(MEDIUM) and cannot
    // appear in the haveibeenpwned breach corpus the NotCompromisedPassword
    // constraint queries over the network.
    private const STRONG_PASSWORD = 'N3w-User!TestSuite#2026-qW8zxCvB';

    public function testRegisterPageRenders(): void
    {
        $this->client->request('GET', '/register');

        self::assertResponseIsSuccessful();
    }

    public function testValidRegistrationCreatesUnverifiedUserAndSendsConfirmationEmail(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Register')->form([
            'registration_form[email]' => 'newuser@example.com',
            'registration_form[plainPassword][first]' => self::STRONG_PASSWORD,
            'registration_form[plainPassword][second]' => self::STRONG_PASSWORD,
            'registration_form[agreeTerms]' => '1',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/login');

        $user = $this->freshUser('newuser@example.com');
        self::assertNotNull($user);
        self::assertFalse($user->isVerified());

        self::assertEmailCount(1);
        $email = $this->getMailerMessage(0);
        self::assertEmailSubjectContains($email, 'Please Confirm your Email');
        self::assertEmailAddressContains($email, 'To', 'newuser@example.com');
    }

    public function testNewAccountCannotLogInBeforeVerification(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Register')->form([
            'registration_form[email]' => 'newuser@example.com',
            'registration_form[plainPassword][first]' => self::STRONG_PASSWORD,
            'registration_form[plainPassword][second]' => self::STRONG_PASSWORD,
            'registration_form[agreeTerms]' => '1',
        ]);
        $this->client->submit($form);

        $this->login('newuser@example.com', self::STRONG_PASSWORD);

        self::assertResponseRedirects('/login');
        self::assertNotNull($this->freshUser('newuser@example.com'));
        self::assertFalse($this->freshUser('newuser@example.com')->isVerified());
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $this->createUser('taken@example.com', 'Fixture-Password-1', true);

        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Register')->form([
            'registration_form[email]' => 'taken@example.com',
            'registration_form[plainPassword][first]' => self::STRONG_PASSWORD,
            'registration_form[plainPassword][second]' => self::STRONG_PASSWORD,
            'registration_form[agreeTerms]' => '1',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);
        self::assertCount(
            1,
            $this->em->getRepository(User::class)->findBy(['email' => 'taken@example.com']),
        );
    }

    public function testWeakPasswordIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Register')->form([
            'registration_form[email]' => 'weakpass@example.com',
            'registration_form[plainPassword][first]' => 'short',
            'registration_form[plainPassword][second]' => 'short',
            'registration_form[agreeTerms]' => '1',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->freshUser('weakpass@example.com'));
        self::assertEmailCount(0);
    }

    public function testMissingTermsAgreementIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Register')->form([
            'registration_form[email]' => 'noterms@example.com',
            'registration_form[plainPassword][first]' => self::STRONG_PASSWORD,
            'registration_form[plainPassword][second]' => self::STRONG_PASSWORD,
        ]);
        $form['registration_form[agreeTerms]']->untick();
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->freshUser('noterms@example.com'));
        self::assertEmailCount(0);
    }

    public function testMismatchedPasswordsAreRejected(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Register')->form([
            'registration_form[email]' => 'mismatch@example.com',
            'registration_form[plainPassword][first]' => self::STRONG_PASSWORD,
            'registration_form[plainPassword][second]' => 'Different!Password#2026-qW8zxCvB',
            'registration_form[agreeTerms]' => '1',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->freshUser('mismatch@example.com'));
        self::assertEmailCount(0);
    }

    public function testGenuineVerificationLinkActivatesTheAccount(): void
    {
        $user = $this->createUser('verifyme@example.com', 'Fixture-Password-1', false);

        $this->client->request('GET', $this->signedVerificationPath($user));

        self::assertResponseRedirects('/login');
        self::assertTrue($this->freshUser('verifyme@example.com')->isVerified());
    }

    public function testVerifiedAccountCanLogInAfterVerification(): void
    {
        $user = $this->createUser('verifyme@example.com', 'Fixture-Password-1', false);
        $this->client->request('GET', $this->signedVerificationPath($user));

        $this->login('verifyme@example.com', 'Fixture-Password-1');

        self::assertResponseRedirects('/dashboard');
    }

    public function testTamperedVerificationLinkDoesNotActivateTheAccount(): void
    {
        $user = $this->createUser('victim@example.com', 'Fixture-Password-1', false);

        $path = $this->signedVerificationPath($user);
        $tampered = preg_replace('/([?&]signature=)[^&]+/', '\1corrupted', $path);
        self::assertNotSame($path, $tampered, 'precondition: the signed URL carries a signature');

        $this->client->request('GET', $tampered);

        self::assertResponseRedirects('/register');
        self::assertFalse($this->freshUser('victim@example.com')->isVerified());
    }

    public function testVerificationWithoutEmailParameterReturnsHome(): void
    {
        $this->client->request('GET', '/verify/email');

        self::assertResponseRedirects('/');
    }

    public function testResendActivationEmailSendsToExistingUser(): void
    {
        $this->createUser('resendme@example.com', 'Fixture-Password-1', false);

        $this->client->request('POST', '/register/activation/resend', [
            'email' => 'resendme@example.com',
            '_token' => $this->resendCsrfToken(),
        ]);

        self::assertResponseRedirects('/login');
        self::assertEmailCount(1);
        $email = $this->getMailerMessage(0);
        self::assertEmailAddressContains($email, 'To', 'resendme@example.com');
    }

    public function testResendActivationEmailForUnknownAddressSendsNothing(): void
    {
        $this->client->request('POST', '/register/activation/resend', [
            'email' => 'ghost@example.com',
            '_token' => $this->resendCsrfToken(),
        ]);

        // Same redirect either way: the endpoint must not reveal registrations.
        self::assertResponseRedirects('/login');
        self::assertEmailCount(0);
    }

    /**
     * Builds the same signed URL the confirmation email carries, so the test
     * visits exactly what a user clicking the email would visit.
     */
    private function signedVerificationPath(User $user): string
    {
        /** @var VerifyEmailHelperInterface $helper */
        $helper = static::getContainer()->get(VerifyEmailHelperInterface::class);

        $signedUrl = $helper->generateSignature(
            'app_verify_email',
            (string) $user->getId(),
            $user->getEmail(),
            ['email' => $user->getEmail()],
        )->getSignedUrl();

        $parts = parse_url($signedUrl);
        $path = $parts['path'] ?? '/verify/email';

        return $path.'?'.($parts['query'] ?? '');
    }
}
