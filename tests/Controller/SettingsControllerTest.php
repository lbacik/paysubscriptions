<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\ResetDatabase;

final class SettingsControllerTest extends WebTestCase
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

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/settings');

        self::assertResponseRedirects('/login');
    }

    public function testUserSeesCurrentSettings(): void
    {
        $user = $this->createUser('viewer@example.com', 'Europe/Warsaw', true, 5);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/settings');

        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Save settings')->form();
        self::assertSame('Europe/Warsaw', $form->get('settings[timezone]')->getValue());
        self::assertSame('5', $form->get('settings[reminderLeadDays]')->getValue());
        self::assertCount(1, $crawler->filter('#settings_emailRemindersEnabled[checked]'));
    }

    public function testUserCanUpdateOwnSettings(): void
    {
        $user = $this->createUser('editor@example.com');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/settings');
        $form = $crawler->selectButton('Save settings')->form([
            'settings[timezone]' => 'America/New_York',
            'settings[reminderLeadDays]' => '7',
        ]);
        $form->get('settings[emailRemindersEnabled]')->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/settings');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $updated = $this->em->getRepository(User::class)->findOneBy(['email' => 'editor@example.com']);
        self::assertSame('America/New_York', $updated->getTimezone());
        self::assertSame(7, $updated->getReminderLeadDays());
        self::assertTrue($updated->isEmailRemindersEnabled());
    }

    public function testInvalidSubmissionKeepsStoredSettings(): void
    {
        $user = $this->createUser('invalid@example.com', 'Europe/Warsaw', false, 3);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/settings');
        // Forge the raw POST (as a hand-crafted request would): the rendered
        // timezone select cannot hold an invalid value client-side.
        $token = $crawler->filter('#settings__token')->attr('value');
        $this->client->request('POST', '/settings', [
            'settings' => [
                'timezone' => 'Mars/Olympus',
                'reminderLeadDays' => '99',
                '_token' => $token,
            ],
        ]);

        // an invalid submission is re-rendered as unprocessable, not saved
        self::assertResponseStatusCodeSame(422);

        $this->em->clear();
        $unchanged = $this->em->getRepository(User::class)->findOneBy(['email' => 'invalid@example.com']);
        self::assertSame('Europe/Warsaw', $unchanged->getTimezone());
        self::assertSame(3, $unchanged->getReminderLeadDays());
        self::assertFalse($unchanged->isEmailRemindersEnabled());
    }

    public function testSettingsAreAccountIsolated(): void
    {
        $user = $this->createUser('owner@example.com');
        $other = $this->createUser('neighbour@example.com', 'Pacific/Auckland', true, 10);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/settings');
        $form = $crawler->selectButton('Save settings')->form([
            'settings[timezone]' => 'Europe/Warsaw',
            'settings[reminderLeadDays]' => '5',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/settings');

        $this->em->clear();
        $untouched = $this->em->getRepository(User::class)->findOneBy(['email' => 'neighbour@example.com']);
        self::assertSame('Pacific/Auckland', $untouched->getTimezone());
        self::assertSame(10, $untouched->getReminderLeadDays());
        self::assertTrue($untouched->isEmailRemindersEnabled());
    }

    public function testUpdatingSettingsLeavesSubscriptionDatesUntouched(): void
    {
        $user = $this->createUser('subscriber@example.com');
        $firstPayment = new \DateTime('2024-03-15');
        $subscription = (new Subscription())
            ->setName('Example Music')
            ->setBillingCycle(BillingCycle::Monthly)
            ->setAmount(9.99)
            ->setNextPayment($firstPayment)
            ->setOwner($user);
        $this->em->persist($subscription);
        $this->em->flush();
        $subscriptionId = $subscription->getId();

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/settings');
        $form = $crawler->selectButton('Save settings')->form([
            'settings[timezone]' => 'Asia/Tokyo',
            'settings[reminderLeadDays]' => '2',
        ]);
        $form->get('settings[emailRemindersEnabled]')->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/settings');

        $this->em->clear();
        $reloaded = $this->em->getRepository(Subscription::class)->find($subscriptionId);
        self::assertSame('2024-03-15', $reloaded->getNextPayment()->format('Y-m-d'));
        self::assertSame('Example Music', $reloaded->getName());
    }

    private function createUser(
        string $email,
        string $timezone = 'UTC',
        bool $emailRemindersEnabled = false,
        int $reminderLeadDays = 3,
    ): User {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = (new User())
            ->setEmail($email)
            ->setVerified(true)
            ->setTimezone($timezone)
            ->setEmailRemindersEnabled($emailRemindersEnabled)
            ->setReminderLeadDays($reminderLeadDays);
        $user->setPassword($hasher->hashPassword($user, 'LongTestPassword123!'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
