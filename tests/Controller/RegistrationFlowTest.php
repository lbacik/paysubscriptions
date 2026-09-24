<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Behavioral coverage for the registration journey (issue #35).
 *
 * Valid and invalid submissions must never produce an HTTP 500. A valid
 * submission creates a usable (unverified) account and queues a verification
 * email; duplicates and invalid input re-render with errors instead.
 */
final class RegistrationFlowTest extends WebTestCase
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

    public function testInvalidSubmissionReRendersWithout500AndCreatesNothing(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Register')->form([
            'registration_form[email]' => 'not-an-email',
            'registration_form[plainPassword][first]' => 'short',
            'registration_form[plainPassword][second]' => 'short',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertNull(
            $this->em->getRepository(User::class)->findOneBy(['email' => 'not-an-email'])
        );
        self::assertSame([], $this->queuedEmails());
    }

    public function testValidSubmissionCreatesAccountAndQueuesVerificationEmail(): void
    {
        $this->register('newuser@example.com');

        self::assertResponseRedirects('/login');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'newuser@example.com']);
        self::assertNotNull($user);
        self::assertFalse($user->isVerified());

        $emails = $this->queuedEmails();
        self::assertCount(1, $emails);
        self::assertSame(['newuser@example.com'], $this->recipientAddresses($emails[0]));
    }

    public function testDuplicateEmailReRendersWithout500(): void
    {
        $this->register('dupe@example.com');
        self::assertResponseRedirects('/login');
        $this->purgeQueuedEmails();

        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Register')->form([
            'registration_form[email]' => 'dupe@example.com',
            'registration_form[plainPassword][first]' => 'CorrectHorseBatteryStaple99!',
            'registration_form[plainPassword][second]' => 'CorrectHorseBatteryStaple99!',
            'registration_form[timezone]' => 'Europe/Warsaw',
        ]);
        $form->get('registration_form[agreeTerms]')->tick();
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'already an account',
            strip_tags((string) $this->client->getResponse()->getContent())
        );
        self::assertSame([], $this->queuedEmails());
    }

    private function register(string $email): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Register')->form([
            'registration_form[email]' => $email,
            'registration_form[plainPassword][first]' => 'CorrectHorseBatteryStaple99!',
            'registration_form[plainPassword][second]' => 'CorrectHorseBatteryStaple99!',
            'registration_form[timezone]' => 'Europe/Warsaw',
        ]);
        $form->get('registration_form[agreeTerms]')->tick();
        $this->client->submit($form);
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
