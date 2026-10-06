<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\RecipientRestriction;
use App\Repository\RecipientRestrictionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Clears the recipient restriction of one address (issue #189).
 *
 * The only way a restriction is ever loosened: SES feedback only tightens it.
 * Authentication is shell access to the production host; the operator names
 * themselves with --operator and must give a --reason. Every clear is written
 * to the `audit` log channel with the previous state before the delete is
 * committed, so it can be reviewed.
 * A later bounce or complaint for the address restricts it again.
 */
#[AsCommand(
    name: 'app:ses:clear-recipient-restriction',
    description: 'Clear the recipient restriction of one email address (audited operator action).',
)]
final class ClearRecipientRestrictionCommand extends Command
{
    public function __construct(
        private readonly RecipientRestrictionRepository $restrictions,
        #[Target('audit')]
        private readonly LoggerInterface $auditLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The restricted email address (normalized before lookup).')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why the restriction is cleared; recorded in the audit log.')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Who clears the restriction; recorded in the audit log.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $reason = trim((string) $input->getOption('reason'));
        $operator = trim((string) $input->getOption('operator'));
        if ('' === $reason || '' === $operator) {
            $io->error('Both --reason and --operator are required and must not be empty.');

            return Command::INVALID;
        }

        $email = (string) $input->getArgument('email');
        $cleared = $this->restrictions->clear($email, fn (RecipientRestriction $restriction) => $this->auditLogger->notice('Recipient restriction cleared by an operator.', [
            'action' => 'recipient_restriction.cleared',
            'email' => $restriction->getEmail(),
            'previous_state' => $restriction->getState()->value,
            'restricted_since' => $restriction->getCreatedAt()->format(\DATE_ATOM),
            'last_tightened_at' => $restriction->getUpdatedAt()->format(\DATE_ATOM),
            'reason' => $reason,
            'operator' => $operator,
            'process_user' => self::processUser(),
        ]));
        if (null === $cleared) {
            $io->error(\sprintf('No recipient restriction exists for %s.', RecipientRestrictionRepository::normalize($email)));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Cleared the %s restriction of %s.', $cleared->getState()->value, $cleared->getEmail()));

        return Command::SUCCESS;
    }

    private static function processUser(): string
    {
        if (\function_exists('posix_geteuid') && \function_exists('posix_getpwuid')) {
            $entry = posix_getpwuid(posix_geteuid());
            if (\is_array($entry)) {
                return $entry['name'];
            }
        }

        return (string) (getenv('USER') ?: getenv('LOGNAME') ?: 'unknown');
    }
}
