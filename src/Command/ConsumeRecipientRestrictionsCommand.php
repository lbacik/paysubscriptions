<?php

declare(strict_types=1);

namespace App\Command;

use App\Mailer\InvalidSesFeedback;
use App\Mailer\SesRestrictionFeedback;
use AsyncAws\Sqs\SqsClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drains the paysubs SES recipient-restriction queue into recipient_restriction.
 *
 * Long-running worker, like `messenger:consume`: run it under a supervisor
 * with --time-limit, or from a scheduler with --once. A message is deleted
 * only after its restriction is committed. An invalid message is left on the
 * queue so it reaches the dead-letter queue after five receives and raises the
 * operator alarm; a failure while recording leaves it for redelivery.
 * Output and logs carry counts and SQS message IDs only, never addresses.
 */
#[AsCommand(
    name: 'app:ses:consume-recipient-restrictions',
    description: 'Record SES permanent bounces and complaints from the paysubs restriction queue.',
)]
final class ConsumeRecipientRestrictionsCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'app.ses_restriction_sqs_client')]
        private readonly SqsClient $sqs,
        private readonly SesRestrictionFeedback $feedback,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(SES_RESTRICTION_QUEUE_URL)%')]
        private readonly string $queueUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Receive one batch (up to 10 messages, 20 s long poll) and exit.')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds.', '3600');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ('' === trim($this->queueUrl)) {
            $output->writeln('<error>SES_RESTRICTION_QUEUE_URL is not set.</error>');

            return Command::FAILURE;
        }

        $deadline = time() + max(1, (int) $input->getOption('time-limit'));
        $totals = ['applied' => 0, 'rejected' => 0, 'retry' => 0];

        do {
            foreach ($this->receiveBatch() as $outcome) {
                ++$totals[$outcome];
            }
        } while (!$input->getOption('once') && time() < $deadline);

        $output->writeln(\sprintf('applied: %d rejected: %d retry: %d', $totals['applied'], $totals['rejected'], $totals['retry']));

        return Command::SUCCESS;
    }

    /**
     * @return list<'applied'|'rejected'|'retry'>
     */
    private function receiveBatch(): array
    {
        $messages = $this->sqs->receiveMessage([
            'QueueUrl' => $this->queueUrl,
            'MaxNumberOfMessages' => 10,
            'WaitTimeSeconds' => 20,
        ])->getMessages();

        $outcomes = [];
        foreach ($messages as $message) {
            $context = ['messageId' => $message->getMessageId()];
            try {
                $this->feedback->apply((string) $message->getBody());
            } catch (InvalidSesFeedback $invalid) {
                $this->logger->error('SES feedback message rejected; it will reach the dead-letter queue.', $context + ['reason' => $invalid->getMessage()]);
                $outcomes[] = 'rejected';

                continue;
            } catch (\Throwable $failure) {
                $this->logger->error('SES feedback message could not be recorded; it will be redelivered.', $context + ['exception' => $failure::class]);
                $outcomes[] = 'retry';

                continue;
            }

            $this->sqs->deleteMessage(['QueueUrl' => $this->queueUrl, 'ReceiptHandle' => (string) $message->getReceiptHandle()])->resolve();
            $outcomes[] = 'applied';
        }

        return $outcomes;
    }
}
