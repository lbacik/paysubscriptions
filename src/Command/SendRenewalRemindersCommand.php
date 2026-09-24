<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\RenewalReminderService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sends due forecast-renewal email reminders.
 *
 * Intended for a daily scheduler (cron). The run is idempotent: each
 * scheduled renewal carries a durable send identity, so overlapping runs and
 * routine retries never duplicate a reminder. Output reports counts only —
 * never Subscription details — while ambiguous provider outcomes stay visible
 * as needs-review rows for manual follow-up.
 */
#[AsCommand(
    name: 'app:send-renewal-reminders',
    description: 'Send due forecast-renewal email reminders to opted-in Users.',
)]
final class SendRenewalRemindersCommand extends Command
{
    public function __construct(
        private readonly RenewalReminderService $reminders,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List due renewals without claiming or sending.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('dry-run')) {
            $due = $this->reminders->previewDueReminders();

            foreach ($due as $row) {
                // Identifiers and dates only: no names, amounts, or emails.
                $output->writeln(sprintf('%s %s', $row['subscription_id'], $row['renewal_date']));
            }
            $output->writeln(sprintf('due: %d (dry run, nothing sent)', \count($due)));

            return Command::SUCCESS;
        }

        $outcome = $this->reminders->sendDueReminders();
        $output->writeln(sprintf(
            'sent: %d skipped: %d needs_review: %d',
            $outcome->sent,
            $outcome->skipped,
            $outcome->needsReview
        ));

        return Command::SUCCESS;
    }
}
