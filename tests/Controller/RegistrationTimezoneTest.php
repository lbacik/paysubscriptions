<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

final class RegistrationTimezoneTest extends WebTestCase
{
    use ResetDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM App\Entity\Subscription s')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\ResetPasswordRequest r')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Limits l')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User u')->execute();
    }

    public function testRegistrationFormDetectsBrowserTimezone(): void
    {
        $crawler = $this->client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller="timezone"]'));
        self::assertCount(1, $crawler->filter('input[type="hidden"][data-timezone-target="field"]'));
    }

    public function testRegistrationStoresDetectedTimezone(): void
    {
        $this->register('detected@example.com', 'Europe/Warsaw');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'detected@example.com']);
        self::assertNotNull($user);
        self::assertSame('Europe/Warsaw', $user->getTimezone());
    }

    public function testRegistrationFallsBackToUtcWithoutBrowserZone(): void
    {
        $this->register('nozone@example.com', '');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'nozone@example.com']);
        self::assertNotNull($user);
        self::assertSame('UTC', $user->getTimezone());
    }

    public function testRegistrationFallsBackToUtcWithInvalidBrowserZone(): void
    {
        $this->register('badzone@example.com', 'Mars/Olympus');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'badzone@example.com']);
        self::assertNotNull($user);
        self::assertSame('UTC', $user->getTimezone());
    }

    private function register(string $email, string $timezone): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Register')->form([
            'registration_form[email]' => $email,
            'registration_form[plainPassword][first]' => 'CorrectHorseBatteryStaple99!',
            'registration_form[plainPassword][second]' => 'CorrectHorseBatteryStaple99!',
            'registration_form[timezone]' => $timezone,
        ]);
        $form->get('registration_form[agreeTerms]')->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/login');
    }
}
