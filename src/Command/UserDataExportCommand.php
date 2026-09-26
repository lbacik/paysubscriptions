<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\UserDataExportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Operator fulfillment entry point for manual-request data exports
 * (issue #48).
 *
 * The operator verifies the requester's identity first (see
 * docs/data-export.md), then runs this command for the verified account
 * email. Output holds only that User's application records as JSON: account
 * profile, Subscriptions, ExpenseCategories, and Limits. Credentials,
 * password-reset tokens, reminder send-state, messenger rows, other Users'
 * records, and the separate newsletter list are never included.
 */
#[AsCommand(
    name: 'user:export',
    description: 'Export one user\'s own account and subscription data as JSON',
)]
class UserDataExportCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserDataExportService $exporter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('user', InputArgument::REQUIRED, 'Account email address verified during request intake')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the JSON export to this file instead of stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $userEmail = $input->getArgument('user');
        \assert(\is_string($userEmail));
        $user = $this->userRepository->findOneBy(['email' => $userEmail]);

        if (null === $user) {
            $io->error('User not found');

            return Command::FAILURE;
        }

        $payload = json_encode(
            $this->exporter->export($user),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        \assert(\is_string($payload));

        $outputPath = $input->getOption('output');
        if (\is_string($outputPath) && '' !== $outputPath) {
            file_put_contents($outputPath, $payload . "\n");
            $io->success(\sprintf('Export for %s written to %s', $userEmail, $outputPath));

            return Command::SUCCESS;
        }

        $output->writeln($payload);

        return Command::SUCCESS;
    }
}
