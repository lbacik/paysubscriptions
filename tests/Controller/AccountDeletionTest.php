<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Limits;
use App\Entity\ResetPasswordRequest;
use App\Enum\BillingCycle;
use App\Entity\Subscription;
use App\Entity\User;
use App\Message\MailingSubscribe;
use App\Service\AccountDeletionService;
use App\Tests\DatabaseTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountDeletionTest extends DatabaseTestCase
{
    public function testAnonymousGetIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/account/delete');

        self::assertResponseRedirects('/login');
    }

    public function testAnonymousPostIsRedirectedToLogin(): void
    {
        $this->client->request('POST', '/account/delete', ['confirm' => '1']);

        self::assertResponseRedirects('/login');
    }

    public function testDeletePageExplainsPermanenceAndSeparateNewsletter(): void
    {
        $user = $this->createUser('reader@example.com');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/account/delete');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('permanently', strtolower($this->client->getResponse()->getContent()));
        self::assertStringContainsString('gprodb.com', $this->client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('form[action="/account/delete"] input[name="_token"]'));
        self::assertCount(1, $crawler->filter('form[action="/account/delete"] input[name="confirm"]'));
    }

    public function testPostWithoutCsrfTokenKeepsAccount(): void
    {
        $user = $this->createUser('csrf@example.com');
        $this->client->loginUser($user);

        $this->client->request('POST', '/account/delete', ['confirm' => '1']);

        self::assertResponseStatusCodeSame(422);
        self::assertNotNull($this->findUser('csrf@example.com'));
    }

    public function testPostWithoutConfirmationKeepsAccount(): void
    {
        $user = $this->createUser('unconfirmed@example.com');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/account/delete');
        $form = $crawler->selectButton('Delete my account permanently')->form();
        // CSRF token present, confirmation checkbox left unticked.
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertNotNull($this->findUser('unconfirmed@example.com'));
    }

    public function testDeleteRemovesUserAndAllOwnedRecords(): void
    {
        $user = $this->createUser('deleted@example.com');
        $this->createSubscription($user, 'Netflix');
        $this->createSubscription($user, 'Spotify');
        $this->createLimits($user, 50);
        $this->createResetPasswordRequest($user);

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/account/delete');
        $form = $crawler->selectButton('Delete my account permanently')->form();
        $form['confirm']->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $em = $this->freshEm();
        self::assertNull($em->getRepository(User::class)->findOneBy(['email' => 'deleted@example.com']));
        self::assertSame(0, \count($em->getRepository(Subscription::class)->findAll()));
        self::assertSame(0, \count($em->getRepository(Limits::class)->findAll()));
        self::assertSame(0, \count($em->getRepository(ResetPasswordRequest::class)->findAll()));
    }

    public function testDeleteLeavesAnotherUsersDataUntouched(): void
    {
        $deleted = $this->createUser('gone@example.com');
        $kept = $this->createUser('kept@example.com');
        $this->createSubscription($deleted, 'Doomed Sub');
        $this->createSubscription($kept, 'Kept Sub');
        $this->createLimits($deleted, 10);
        $this->createLimits($kept, 20);
        $this->createResetPasswordRequest($deleted);
        $this->createResetPasswordRequest($kept);

        $this->client->loginUser($deleted);

        $crawler = $this->client->request('GET', '/account/delete');
        $form = $crawler->selectButton('Delete my account permanently')->form();
        $form['confirm']->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/');

        $em = $this->freshEm();
        $survivor = $em->getRepository(User::class)->findOneBy(['email' => 'kept@example.com']);
        self::assertNotNull($survivor);
        self::assertSame('Kept Sub', $em->getRepository(Subscription::class)->findOneBy([])->getName());
        self::assertSame(20, $survivor->getSubscriptionsLimit());
        self::assertSame(1, \count($em->getRepository(ResetPasswordRequest::class)->findAll()));
    }

    public function testSessionIsInvalidatedAfterDeletion(): void
    {
        $user = $this->createUser('session@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/account/delete');
        $form = $crawler->selectButton('Delete my account permanently')->form();
        $form['confirm']->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/');

        // The pre-deletion session no longer authenticates anything.
        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function testPendingQueuedMessagesForDeletedAddressArePurged(): void
    {
        $user = $this->createUser('queued@example.com');
        $other = $this->createUser('other@example.com');
        $this->queueMessage(['to' => 'queued@example.com', 'subject' => 'Verify your email']);
        $this->queueMessage(['to' => 'queued@example.com', 'subject' => 'Your password reset request']);
        $this->queueMessage(['to' => 'other@example.com', 'subject' => 'Verify your email']);
        // A longer address merely containing the deleted one must not match.
        $this->queueMessage(['to' => 'notqueued@example.com', 'subject' => 'Verify your email']);

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/account/delete');
        $form = $crawler->selectButton('Delete my account permanently')->form();
        $form['confirm']->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/');

        $rows = $this->freshEm()->getConnection()->fetchAllAssociative('SELECT body FROM messenger_messages');
        self::assertCount(2, $rows);
        self::assertStringContainsString('other@example.com', $rows[0]['body']);
        self::assertStringContainsString('notqueued@example.com', $rows[1]['body']);
    }

    public function testNewsletterSubscriptionIsLeftUntouched(): void
    {
        $user = $this->createUser('newsletter@example.com');

        $bus = static::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new MailingSubscribe('newsletter@example.com', 'project-uuid'));

        static::getContainer()->get(AccountDeletionService::class)->delete($user);

        /** @var \Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.newsletter');
        $sent = iterator_to_array($transport->getSent());

        self::assertCount(1, $sent);
        self::assertSame('newsletter@example.com', $sent[0]->getMessage()->email);
    }

    public function testVerificationLinkCannotRecreateDeletedAccount(): void
    {
        $user = $this->createUser('verify-me@example.com');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/account/delete');
        $form = $crawler->selectButton('Delete my account permanently')->form();
        $form['confirm']->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/');

        $this->client->request('GET', '/verify/email?email=verify-me@example.com');
        self::assertResponseRedirects('/');
        self::assertNull($this->findUser('verify-me@example.com'));
    }

    public function testPasswordResetRequestAfterDeletionRevealsNothingAndStoresNothing(): void
    {
        $user = $this->createUser('reset-me@example.com');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/account/delete');
        $form = $crawler->selectButton('Delete my account permanently')->form();
        $form['confirm']->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/');

        $crawler = $this->client->request('GET', '/reset-password');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Send password reset email')->form([
            'reset_password_request_form[email]' => 'reset-me@example.com',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/reset-password/check-email');
        self::assertSame(0, \count($this->freshEm()->getRepository(ResetPasswordRequest::class)->findAll()));
    }

    private function createUser(string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createSubscription(User $user, string $name): Subscription
    {
        $subscription = new Subscription();
        $subscription->setName($name);
        $subscription->setBillingCycle(BillingCycle::Monthly);
        $subscription->setAmount(9.99);
        $subscription->setNextPayment(new \DateTime('2024-01-01'));
        $subscription->setOwner($user);

        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }

    private function createLimits(User $user, int $limit): Limits
    {
        $limits = new Limits($user);
        $limits->setSubscriptions($limit);
        $user->setLimits($limits);

        $this->em->persist($limits);
        $this->em->flush();

        return $limits;
    }

    private function createResetPasswordRequest(User $user): ResetPasswordRequest
    {
        $request = new ResetPasswordRequest(
            $user,
            new \DateTimeImmutable('+1 hour'),
            substr(bin2hex(random_bytes(16)), 0, 20),
            'hashed-token'
        );

        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    private function queueMessage(array $payload): void
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $this->em->getConnection()->executeStatement(
            'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES (:body, :headers, :queue, :created, :available)',
            [
                'body' => json_encode($payload),
                'headers' => '{}',
                'queue' => 'default',
                'created' => $now,
                'available' => $now,
            ]
        );
    }

    private function findUser(string $email): ?User
    {
        return $this->freshEm()->getRepository(User::class)->findOneBy(['email' => $email]);
    }
}
