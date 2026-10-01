<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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
    use MailerAssertionsTrait;
    use ResetDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
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
        self::assertEmailCount(0);
    }

    public function testValidSubmissionCreatesAccountAndQueuesVerificationEmail(): void
    {
        $this->register('newuser@example.com');

        self::assertResponseRedirects('/login');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'newuser@example.com']);
        self::assertNotNull($user);
        self::assertFalse($user->isVerified());

        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        \assert($email instanceof Email);
        self::assertSame(['newuser@example.com'], $this->recipientAddresses($email));
    }

    public function testDuplicateEmailGetsNeutralResponseAndNotifiesOwner(): void
    {
        $this->register('dupe@example.com');
        self::assertResponseRedirects('/login');
        $freshBody = $this->followRedirectText();

        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Register')->form([
            'registration_form[email]' => 'dupe@example.com',
            'registration_form[plainPassword][first]' => 'CorrectHorseBatteryStaple99!',
            'registration_form[plainPassword][second]' => 'CorrectHorseBatteryStaple99!',
            'registration_form[timezone]' => 'Europe/Warsaw',
        ]);
        $form->get('registration_form[agreeTerms]')->tick();
        $this->client->submit($form);

        // Same outward outcome as the fresh registration above: the response
        // must not reveal that the address is taken.
        self::assertResponseRedirects('/login');

        // Assert the owner notice before following the redirect: the next
        // request reboots the kernel and clears the test mail logger.
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        \assert($email instanceof Email);
        self::assertSame(['dupe@example.com'], $this->recipientAddresses($email));

        self::assertSame($freshBody, $this->followRedirectText());
    }

    private function followRedirectText(): string
    {
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        return $crawler->text(null, true);
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
     * @return list<string>
     */
    private function recipientAddresses(Email $email): array
    {
        return array_map(
            static fn ($address) => $address->getAddress(),
            $email->getTo()
        );
    }
}
