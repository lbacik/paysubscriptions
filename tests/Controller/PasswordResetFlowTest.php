<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Behavioral coverage for the password-reset journey (issue #35).
 *
 * Valid and invalid submissions must never produce an HTTP 500. Unknown
 * addresses receive the same safe response as known ones (no account
 * enumeration), a known address queues a reset email, and a token completes
 * into a usable new password.
 */
final class PasswordResetFlowTest extends WebTestCase
{
    use ResetDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeQueuedEmails();
    }

    public function testInvalidSubmissionReRendersWithout500(): void
    {
        $crawler = $this->client->request('GET', '/reset-password');
        $form = $crawler->filter('form')->form([
            'reset_password_request_form[email]' => '',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->queuedEmails());
    }

    public function testUnknownEmailGetsTheSameSafeResponseAndNoEmail(): void
    {
        $crawler = $this->client->request('GET', '/reset-password');
        $form = $crawler->filter('form')->form([
            'reset_password_request_form[email]' => 'unknown@example.com',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/reset-password/check-email');
        self::assertSame([], $this->queuedEmails());

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testKnownEmailQueuesResetEmail(): void
    {
        $this->createUser('known@example.com');

        $crawler = $this->client->request('GET', '/reset-password');
        $form = $crawler->filter('form')->form([
            'reset_password_request_form[email]' => 'known@example.com',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/reset-password/check-email');

        $emails = $this->queuedEmails();
        self::assertCount(1, $emails);
        self::assertSame(['known@example.com'], $this->recipientAddresses($emails[0]));
    }

    public function testTokenCompletionSetsNewPassword(): void
    {
        $user = $this->createUser('resetme@example.com');

        $helper = static::getContainer()->get(ResetPasswordHelperInterface::class);
        $token = $helper->generateResetToken($user)->getToken();

        // Visiting the emailed link stores the token in the session and redirects.
        $this->client->request('GET', '/reset-password/reset/' . $token);
        self::assertResponseRedirects('/reset-password/reset');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $form = $this->client->getCrawler()->filter('form')->form([
            'change_password_form[plainPassword][first]' => 'BrandNewBatteryStaple99!',
            'change_password_form[plainPassword][second]' => 'BrandNewBatteryStaple99!',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/login');

        // The new password must actually authenticate.
        $this->client->request('GET', '/login');
        $loginForm = $this->client->getCrawler()->selectButton('Sign in')->form([
            'email' => 'resetme@example.com',
            'password' => 'BrandNewBatteryStaple99!',
        ]);
        $this->client->submit($loginForm);

        self::assertResponseRedirects();
    }

    private function createUser(string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setTimezone('UTC')
            ->setVerified(true);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'CorrectHorseBatteryStaple99!'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @return list<Email>
     */
    private function queuedEmails(): array
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        $emails = [];

        foreach ($transport->get() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof SendEmailMessage) {
                $original = $message->getMessage();
                \assert($original instanceof Email);
                $emails[] = $original;
            }
        }

        return $emails;
    }

    /**
     * @return list<string>
     */
    private function recipientAddresses(Email $email): array
    {
        return array_map(
            static fn ($address) => $address->getAddress(),
            $email->getTo()
        );
    }

    private function purgeQueuedEmails(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM messenger_messages');
    }
}
