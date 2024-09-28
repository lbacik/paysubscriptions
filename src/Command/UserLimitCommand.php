<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'user:limit',
    description: 'Manage user limits',
)]
class UserLimitCommand extends Command
{
    public function __construct(
        private UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('user', InputArgument::REQUIRED, 'User email address')
            ->addOption('show', 's', InputOption::VALUE_NONE, 'Show user limit')
            ->addOption('subscriptions', null, InputOption::VALUE_REQUIRED, 'Set user subscriptions limit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $userEmail = $input->getArgument('user');
        $user = $this->userRepository->findOneBy(['email' => $userEmail]);

        if (!$user) {
            $io->error('User not found');
            return Command::FAILURE;
        }

        if ($input->getOption('subscriptions')) {
            $limitValue = (int)$input->getOption('subscriptions');
            $user->setSubscriptionsLimit($limitValue);

            $this->userRepository->save($user);
            $io->success('User subscriptions limit updated');
        }

        $io->info('User limits');
        $io->table(
            ['Limit', 'Value', 'Used'],
            [
                ['subscriptions', $user->getSubscriptionsLimit(), count($user->getSubscriptions())],
            ]
        );

        return Command::SUCCESS;
    }
}
