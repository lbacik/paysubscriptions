<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RenewalReminder;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\ReminderStatus;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Schedules and sends forecast-renewal email reminders.
 *
 * Each scheduled renewal gets a durable send identity (one RenewalReminder
 * row per subscription + renewal date): concurrent workers and routine
 * retries claim the same row instead of sending twice. Delivery follows a
 * strict order per renewal — claim, recheck current rows, build, send — so
 * that:
 *
 * - failures before the message reaches the provider release the claim and
 *   may retry on a later run;
 * - failures while sending are ambiguous (the provider may have accepted the
 *   message), so the row is kept as needs-review for manual follow-up and is
 *   never blindly resent.
 *
 * Logs and console output carry counts and row identifiers only: never
 * Subscription names, amounts, or email addresses.
 */
final class RenewalReminderService
{
    /**
     * A pending claim older than this is treated as an abandoned worker and
     * may be reclaimed; a fresher one belongs to a live concurrent worker.
     */
    private const CLAIM_TTL_SECONDS = 3600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RenewalReminderPlanner $planner,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        private readonly string $systemEmail,
    ) {
    }

    public function sendDueReminders(?\DateTimeImmutable $now = null): RenewalReminderOutcome
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $outcome = new RenewalReminderOutcome();

        // Every User is visited, not just opted-in ones: a pending claim made
        // before an opt-out must still be released, and the planner (plus the
        // pre-delivery recheck) enforces opt-in before anything is sent.
        foreach ($this->usersToVisit() as $user) {
            foreach ($this->subscriptionsOf($user) as $subscription) {
                $this->sendIfDue($user, $subscription, $now, $outcome);
            }
        }

        $this->logger->info('Renewal reminders run finished', [
            'sent' => $outcome->sent,
            'skipped' => $outcome->skipped,
            'needs_review' => $outcome->needsReview,
        ]);

        return $outcome;
    }

    /**
     * Lists due renewals without claiming or sending anything. Identifiers
     * and dates only: safe for console output and dry runs. Renewals already
     * handled (sent or awaiting review) are not due again, so they are
     * excluded — the preview mirrors what a live run would attempt.
     *
     * @return list<array{subscription_id: string, renewal_date: string}>
     */
    public function previewDueReminders(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $due = [];

        foreach ($this->usersToVisit() as $user) {
            foreach ($this->subscriptionsOf($user) as $subscription) {
                $renewalDate = $this->planner->dueRenewalDate($subscription, $user, $now);

                if (null === $renewalDate) {
                    continue;
                }

                $existing = $this->em->getRepository(RenewalReminder::class)
                    ->findForSubscriptionOnDate($subscription, $renewalDate);

                if (null !== $existing && ReminderStatus::Pending !== $existing->getStatus()) {
                    continue;
                }

                $due[] = [
                    'subscription_id' => $subscription->getId()->toRfc4122(),
                    'renewal_date' => $renewalDate->format('Y-m-d'),
                ];
            }
        }

        return $due;
    }

    /**
     * Identifiers of reminders awaiting manual review, oldest first.
     * Identifiers only — no Subscription details — so the list is safe for
     * console output and log correlation.
     *
     * @return list<string>
     */
    public function needsReviewIds(): array
    {
        return array_map(
            static fn (RenewalReminder $reminder): string => (string) $reminder->getId()->toRfc4122(),
            $this->em->getRepository(RenewalReminder::class)->findNeedingReview()
        );
    }

    /**
     * @return list<User>
     */
    private function usersToVisit(): array
    {
        return $this->em->getRepository(User::class)->findAll();
    }

    /**
     * @return list<Subscription>
     */
    private function subscriptionsOf(User $user): array
    {
        return $this->em->getRepository(Subscription::class)->findBy(['owner' => $user], ['name' => 'ASC']);
    }

    private function sendIfDue(
        User $user,
        Subscription $subscription,
        \DateTimeImmutable $now,
        RenewalReminderOutcome $outcome,
    ): void {
        $due = $this->planner->dueRenewalDate($subscription, $user, $now);

        // Drop pending identities this renewal no longer selects (opt-out,
        // moved date, changed lead time): they must never deliver, and a
        // re-opt-in or a moved-back date claims a fresh identity. Sent and
        // needs-review rows are history and are always kept.
        $this->deleteSupersededPendingClaims($subscription, $due);

        if (null === $due) {
            ++$outcome->skipped;

            return;
        }

        $reminder = $this->claim($subscription, $due, $now);

        if (null === $reminder) {
            // Already sent, awaiting review, or claimed by a live worker.
            ++$outcome->skipped;

            return;
        }

        if (!$this->stillDue($subscription, $due, $now)) {
            // Opt-in, dates, or ownership changed after the claim (or the
            // record is gone): release the identity, never send stale mail.
            $this->releaseClaim($reminder);
            ++$outcome->skipped;

            return;
        }

        try {
            $email = $this->reminderEmail($subscription, $due);
        } catch (\Throwable) {
            // Failed before reaching the provider: release the claim so a
            // later run may retry.
            $this->releaseClaim($reminder);
            $this->logger->error('Renewal reminder build failed; claim released', [
                'reminder_id' => (string) $reminder->getId(),
            ]);
            ++$outcome->skipped;

            return;
        }

        try {
            $this->mailer->send($email);
        } catch (\Throwable) {
            // Ambiguous provider outcome: the message may have been accepted.
            // Keep the identity for manual review; routine retries must not
            // resend it.
            $reminder->setStatus(ReminderStatus::NeedsReview);
            $this->em->flush();
            $this->logger->warning('Renewal reminder needs manual review', [
                'reminder_id' => (string) $reminder->getId(),
            ]);
            ++$outcome->needsReview;

            return;
        }

        $reminder->setStatus(ReminderStatus::Sent);
        $reminder->setSentAt(new \DateTime());
        $this->em->flush();
        $this->logger->info('Renewal reminder sent', [
            'reminder_id' => (string) $reminder->getId(),
        ]);
        ++$outcome->sent;
    }

    /**
     * Removes pending identities for this Subscription that the current
     * schedule no longer selects. A plain DBAL write: deleting an already
     * released row (concurrent worker got there first) affects zero rows
     * instead of failing.
     */
    private function deleteSupersededPendingClaims(Subscription $subscription, ?\DateTimeImmutable $keepDate): void
    {
        $connection = $this->em->getConnection();
        $criteria = [
            'subscription_id' => $subscription->getId()->toBinary(),
            'status' => ReminderStatus::Pending->value,
        ];

        if (null === $keepDate) {
            $connection->delete('renewal_reminder', $criteria);
        } else {
            $connection->executeStatement(
                'DELETE FROM renewal_reminder WHERE subscription_id = ? AND status = ? AND renewal_date <> ?',
                [$criteria['subscription_id'], $criteria['status'], $keepDate->format('Y-m-d')]
            );
        }
    }

    /**
     * Releases a claim without touching the ORM unit of work, so a
     * concurrent worker releasing the same row first cannot break this run.
     */
    private function releaseClaim(RenewalReminder $reminder): void
    {
        $this->em->getConnection()->delete('renewal_reminder', [
            'id' => $reminder->getId()->toBinary(),
        ]);
    }

    /**
     * Inserts the durable send identity with a plain DBAL write (outside the
     * ORM unit of work, so a lost uniqueness race never corrupts the entity
     * manager), then resolves the managed row.
     *
     * Returns null when the renewal was already handled or belongs to a live
     * concurrent worker. A dangling identity (the Subscription or its owner
     * disappeared between listing and claiming) is also a skip: there is
     * nothing to remind about, and a foreign-key failure must never abort
     * the whole run.
     */
    private function claim(
        Subscription $subscription,
        \DateTimeImmutable $due,
        \DateTimeImmutable $now,
    ): ?RenewalReminder {
        $connection = $this->em->getConnection();
        $stamp = $now->format('Y-m-d H:i:s');

        try {
            $connection->insert('renewal_reminder', [
                'id' => Uuid::v4()->toBinary(),
                'subscription_id' => $subscription->getId()->toBinary(),
                'renewal_date' => $due->format('Y-m-d'),
                'status' => ReminderStatus::Pending->value,
                'sent_at' => null,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
        } catch (ForeignKeyConstraintViolationException) {
            return null;
        } catch (UniqueConstraintViolationException) {
            return $this->reclaimIfStale($subscription, $due, $now);
        }

        return $this->em->getRepository(RenewalReminder::class)
            ->findForSubscriptionOnDate($subscription, $due);
    }

    private function reclaimIfStale(
        Subscription $subscription,
        \DateTimeImmutable $due,
        \DateTimeImmutable $now,
    ): ?RenewalReminder {
        $existing = $this->em->getRepository(RenewalReminder::class)
            ->findForSubscriptionOnDate($subscription, $due);

        if (null === $existing || ReminderStatus::Pending !== $existing->getStatus()) {
            return null;
        }

        $claimedAt = $existing->getCreatedAt();

        if (!$claimedAt instanceof \DateTimeInterface
            || $claimedAt->getTimestamp() > $now->getTimestamp() - self::CLAIM_TTL_SECONDS
        ) {
            // A live worker owns this claim.
            return null;
        }

        // The claiming worker died mid-claim: take the identity over.
        $existing->setUpdatedAt(new \DateTime());
        $this->em->flush();

        return $existing;
    }

    /**
     * Re-reads the current rows straight from the database: opt-in, ownership,
     * and the forecast renewal must still select this exact scheduled date.
     */
    private function stillDue(
        Subscription $subscription,
        \DateTimeImmutable $claimedDate,
        \DateTimeImmutable $now,
    ): bool {
        try {
            $this->em->refresh($subscription);
        } catch (\Throwable) {
            return false;
        }

        $owner = $subscription->getOwner();

        if (null === $owner) {
            return false;
        }

        try {
            $this->em->refresh($owner);
        } catch (\Throwable) {
            return false;
        }

        if (!$owner->isEmailRemindersEnabled()) {
            return false;
        }

        $due = $this->planner->dueRenewalDate($subscription, $owner, $now);

        return null !== $due && $due->format('Y-m-d') === $claimedDate->format('Y-m-d');
    }

    private function reminderEmail(Subscription $subscription, \DateTimeImmutable $renewalDate): TemplatedEmail
    {
        $owner = $subscription->getOwner();
        \assert(null !== $owner);

        $amount = $subscription->getAmount();
        $currency = $subscription->getCurrency() ?? $owner->getMainCurrency() ?? '';
        $forecastAmount = null !== $amount ? trim(sprintf('%.2f %s', $amount, $currency)) : '';

        return (new TemplatedEmail())
            ->from(new Address($this->systemEmail, 'PaySubscriptions'))
            ->to((string) $owner->getEmail())
            ->subject(sprintf(
                'Reminder: %s renews on %s (forecast)',
                $subscription->getName(),
                $renewalDate->format('Y-m-d')
            ))
            ->htmlTemplate('emails/renewal_reminder.html.twig')
            ->textTemplate('emails/renewal_reminder.txt.twig')
            ->context([
                'subscriptionName' => $subscription->getName(),
                'forecastAmount' => $forecastAmount,
                'renewalDate' => $renewalDate->format('Y-m-d'),
                'subscriptionUrl' => $this->urls->generate(
                    'app_subscription_edit',
                    ['id' => $subscription->getId()->toRfc4122()],
                    UrlGeneratorInterface::ABSOLUTE_URL
                ),
                'settingsUrl' => $this->urls->generate('app_settings', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
    }
}
