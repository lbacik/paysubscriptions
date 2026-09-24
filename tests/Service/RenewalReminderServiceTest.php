<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\RenewalReminder;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use App\Enum\ReminderStatus;
use App\Service\RenewalCalculator;
use App\Service\RenewalReminderPlanner;
use App\Service\RenewalReminderService;
use App\Tests\DatabaseTestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Renewal reminders: only opted-in Users get mail at their lead time, every
 * send has a durable identity, and retries/workers cannot duplicate one.
 */
final class RenewalReminderServiceTest extends DatabaseTestCase
{
    use MailerAssertionsTrait;

    private RenewalReminderService $reminders;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reminders = static::getContainer()->get(RenewalReminderService::class);
        // Fixed reference instant: 2024-03-10 12:00 UTC (a Sunday; US DST starts this day).
        $this->now = new \DateTimeImmutable('2024-03-10 12:00:00', new \DateTimeZone('UTC'));
    }

    public function testOptedOutUserReceivesNoMail(): void
    {
        $user = $this->optedUser('optout@example.com', false, 3);
        $this->createSubscription($user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-03-13'));

        $outcome = $this->reminders->sendDueReminders($this->now);

        self::assertEmailCount(0);
        self::assertSame(0, $outcome->sent);
        self::assertCount(0, $this->em->getRepository(RenewalReminder::class)->findAll());
    }

    public function testRenewalBeyondLeadTimeReceivesNoMail(): void
    {
        $user = $this->optedUser('far@example.com', true, 3);
        $this->createSubscription($user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-03-14'));

        $outcome = $this->reminders->sendDueReminders($this->now);

        self::assertEmailCount(0);
        self::assertSame(0, $outcome->sent);
    }

    public function testDueRenewalSendsForecastEmailLinkingOwnedSubscription(): void
    {
        $user = $this->optedUser('alice@example.com', true, 3);
        $subscription = $this->createSubscription($user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-03-13'));

        $outcome = $this->reminders->sendDueReminders($this->now);

        self::assertSame(1, $outcome->sent);
        self::assertEmailCount(1);

        $email = $this->getMailerMessage(0);
        self::assertEmailAddressContains($email, 'To', 'alice@example.com');
        self::assertEmailSubjectContains($email, 'Netflix');
        self::assertEmailHtmlBodyContains($email, 'forecast');
        self::assertEmailHtmlBodyContains($email, 'Netflix');
        self::assertEmailHtmlBodyContains($email, '15.99');
        self::assertEmailHtmlBodyContains(
            $email,
            '/subscription/'.$subscription->getId()->toRfc4122().'/edit'
        );
        self::assertEmailTextBodyContains($email, 'forecast');

        $rows = $this->em->getRepository(RenewalReminder::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame(ReminderStatus::Sent, $rows[0]->getStatus());
        self::assertSame('2024-03-13', $rows[0]->getRenewalDate()->format('Y-m-d'));
    }

    public function testRepeatRunDoesNotResendSameScheduledRenewal(): void
    {
        $user = $this->optedUser('repeat@example.com', true, 3);
        $this->createSubscription($user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-03-13'));

        $first = $this->reminders->sendDueReminders($this->now);
        $second = $this->reminders->sendDueReminders($this->now);

        self::assertSame(1, $first->sent);
        self::assertSame(0, $second->sent);
        self::assertEmailCount(1);
        self::assertCount(1, $this->em->getRepository(RenewalReminder::class)->findAll());
    }

    public function testUserWithMultipleDueRenewalsGetsOneEmailPerRenewal(): void
    {
        $user = $this->optedUser('multi@example.com', true, 3);
        $this->createSubscription($user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-03-13'));
        $this->createSubscription($user, 'Spotify', BillingCycle::Monthly, 9.99, new \DateTime('2024-03-11'));

        $outcome = $this->reminders->sendDueReminders($this->now);

        self::assertSame(2, $outcome->sent);
        self::assertEmailCount(2);
        self::assertCount(2, $this->em->getRepository(RenewalReminder::class)->findAll());
    }

    public function testDateEditAfterClaimPreventsStaleMail(): void
    {
        $user = $this->optedUser('edit@example.com', true, 3);
        $subscription = $this->createSubscription($user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-03-13'));

        // A claim exists for the old forecast date, then the User moves the date out.
        $this->claim($subscription, '2024-03-13', ReminderStatus::Pending);
        $subscription->setNextPayment(new \DateTime('2024-06-01'));
        $this->em->flush();

        $outcome = $this->reminders->sendDueReminders($this->now);

        self::assertSame(0, $outcome->sent);
        self::assertEmailCount(0);
        // The stale identity is released, never delivered.
        self::assertCount(0, $this->em->getRepository(RenewalReminder::class)->findAll());
    }

    public function testOptOutAfterClaimPreventsMailAndReOptInRetries(): void
    {
        $user = $this->optedUser('race@example.com', true, 3);
        $subscription = $this->createSubscription($user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-03-13'));

        // Pre-send recheck must observe the opt-out even with a claim in place.
        $this->claim($subscription, '2024-03-13', ReminderStatus::Pending);
        $user->setEmailRemindersEnabled(false);
        $this->em->flush();

        $outcome = $this->reminders->sendDueReminders($this->now);

        self::assertSame(0, $outcome->sent);
        self::assertEmailCount(0);
        self::assertCount(0, $this->em->getRepository(RenewalReminder::class)->findAll());

        // The released claim is retryable: re-opting in delivers exactly once.
        $user->setEmailRemindersEnabled(true);
        $this->em->flush();

        $retry = $this->reminders->sendDueReminders($this->now);

        self::assertSame(1, $retry->sent);
        self::assertEmailCount(1);
    }

    public function testConcurrentFreshClaimIsLeftAlone(): void
    {
        $user = $this->optedUser('concurrent@example.com', true, 3);
        $subscription = $this->createSubscription($user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-03-13'));

        // Another worker claimed this renewal moments ago: do not duplicate it.
        $this->claim($subscription, '2024-03-13', ReminderStatus::Pending);

        $outcome = $this->reminders->sendDueReminders($this->now);

        self::assertSame(0, $outcome->sent);
        self::assertEmailCount(0);

        $rows = $this->em->getRepository(RenewalReminder::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame(ReminderStatus::Pending, $rows[0]->getStatus());
    }

    public function testStalePendingClaimIsReclaimedAndSent(): void
    {
        $user = $this->optedUser('stale@example.com', true, 3);
        $subscription = $this->createSubscription($user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-03-13'));

        // A worker died after claiming but before delivering: the identity is
        // old enough to reclaim, so the reminder still goes out exactly once.
        $this->claim($subscription, '2024-03-13', ReminderStatus::Pending, '2024-03-10 10:00:00');

        $outcome = $this->reminders->sendDueReminders($this->now);

        self::assertSame(1, $outcome->sent);
        self::assertEmailCount(1);

        $rows = $this->em->getRepository(RenewalReminder::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame(ReminderStatus::Sent, $rows[0]->getStatus());
    }

    public function testAmbiguousProviderOutcomeIsKeptForReviewAndNeverResent(): void
    {
        $user = $this->optedUser('outage@example.com', true, 3);
        $subscription = $this->createSubscription($user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-03-13'));

        $failing = $this->serviceWithMailer(new FailingMailer());

        $outcome = $failing->sendDueReminders($this->now);

        self::assertSame(0, $outcome->sent);
        self::assertSame(1, $outcome->needsReview);
        self::assertEmailCount(0);

        $rows = $this->em->getRepository(RenewalReminder::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame(ReminderStatus::NeedsReview, $rows[0]->getStatus());

        // Routine retries must not blindly resend an ambiguous outcome.
        $retry = $failing->sendDueReminders($this->now);

        self::assertSame(0, $retry->sent);
        self::assertSame(0, $retry->needsReview);
        self::assertEmailCount(0);
        self::assertCount(1, $this->em->getRepository(RenewalReminder::class)->findAll());
    }

    private function optedUser(string $email, bool $optIn, int $leadDays): User
    {
        $user = $this->createUser($email);
        $user->setTimezone('UTC');
        $user->setEmailRemindersEnabled($optIn);
        $user->setReminderLeadDays($leadDays);
        $this->em->flush();

        return $user;
    }

    private function claim(Subscription $subscription, string $renewalDate, ReminderStatus $status, ?string $createdAt = null): RenewalReminder
    {
        $reminder = (new RenewalReminder())
            ->setSubscription($subscription)
            ->setRenewalDate(new \DateTimeImmutable($renewalDate))
            ->setStatus($status);
        $this->em->persist($reminder);
        $this->em->flush();

        if (null !== $createdAt) {
            $this->em->createQuery(
                'UPDATE '.RenewalReminder::class.' r SET r.createdAt = :at WHERE r.id = :id'
            )->execute([
                // Relative to the frozen scheduling reference used by the
                // run, not wall-clock time.
                'at' => new \DateTime($createdAt),
                'id' => $reminder->getId()->toBinary(),
            ]);
            $this->em->clear();
        }

        return $reminder;
    }

    private function serviceWithMailer(MailerInterface $mailer): RenewalReminderService
    {
        $container = static::getContainer();

        return new RenewalReminderService(
            $this->em,
            new RenewalReminderPlanner(new RenewalCalculator(), new \App\Service\TimezoneService()),
            $mailer,
            $container->get('router'),
            new NullLogger(),
            'reminders@example.com',
        );
    }
}

final class FailingMailer implements MailerInterface
{
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        throw new TransportException('Simulated provider outage.');
    }
}
