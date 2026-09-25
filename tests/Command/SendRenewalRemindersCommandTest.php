<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SendRenewalRemindersCommand;
use App\Entity\RenewalReminder;
use App\Enum\BillingCycle;
use App\Enum\ReminderStatus;
use App\Tests\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The scheduler entry point reports counts only and honors dry runs.
 */
final class SendRenewalRemindersCommandTest extends DatabaseTestCase
{
    use MailerAssertionsTrait;

    public function testSendReportsCountsAndDeliversDueReminders(): void
    {
        $user = $this->createUser('cron@example.com');
        $user->setTimezone('UTC');
        $user->setEmailRemindersEnabled(true);
        $user->setReminderLeadDays(30);
        $this->em->flush();

        $this->createSubscription($user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('+2 days'));

        $tester = new CommandTester(static::getContainer()->get(SendRenewalRemindersCommand::class));
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('sent: 1', $tester->getDisplay());
        self::assertStringContainsString('needs_review: 0', $tester->getDisplay());
        self::assertEmailCount(1);
        self::assertCount(1, $this->em->getRepository(RenewalReminder::class)->findAll());
    }

    public function testDryRunSendsNothingAndClaimsNothing(): void
    {
        $user = $this->createUser('dryrun@example.com');
        $user->setTimezone('UTC');
        $user->setEmailRemindersEnabled(true);
        $user->setReminderLeadDays(30);
        $this->em->flush();

        $this->createSubscription($user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('+2 days'));

        $tester = new CommandTester(static::getContainer()->get(SendRenewalRemindersCommand::class));
        $status = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('dry run, nothing sent', $tester->getDisplay());
        self::assertEmailCount(0);
        self::assertCount(0, $this->em->getRepository(RenewalReminder::class)->findAll());
    }

    public function testOutstandingNeedsReviewIdentitiesAreListedByIdentifierOnly(): void
    {
        $user = $this->createUser('review@example.com');
        $user->setTimezone('UTC');
        $user->setEmailRemindersEnabled(true);
        $user->setReminderLeadDays(30);
        $this->em->flush();

        $anchor = new \DateTime('+2 days');
        $subscription = $this->createSubscription($user, 'Netflix', BillingCycle::Monthly, 15.99, $anchor);

        $reminder = (new RenewalReminder())
            ->setSubscription($subscription)
            ->setRenewalDate(new \DateTimeImmutable($anchor->format('Y-m-d')))
            ->setStatus(ReminderStatus::NeedsReview);
        $this->em->persist($reminder);
        $this->em->flush();

        $tester = new CommandTester(static::getContainer()->get(SendRenewalRemindersCommand::class));
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        // The ambiguous outcome is never resent, but stays visible by id.
        self::assertStringContainsString('sent: 0', $tester->getDisplay());
        self::assertStringContainsString(
            'needs_review_ids: '.$reminder->getId()->toRfc4122(),
            $tester->getDisplay()
        );
        self::assertStringNotContainsString('Netflix', $tester->getDisplay());
        self::assertEmailCount(0);
    }
}
