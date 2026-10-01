<?php

declare(strict_types=1);

namespace App\Command;

use App\OAuth2\RefreshTokenFamilyRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Revokes every OAuth2 refresh-token family (issue #94).
 *
 * The emergency step of a suspected signing-key compromise: run after the
 * compromised verification key is unpublished (so forged access tokens fail
 * validation) to additionally end every legitimate session. Clients must
 * authorize again; already-issued access tokens stay valid until their
 * short expiry (decision #86). See docs/oauth-key-rotation.md.
 */
#[AsCommand(
    name: 'app:oauth:revoke-refresh-families',
    description: 'Revoke all OAuth2 refresh-token families (emergency key-compromise response)',
)]
final class RevokeRefreshFamiliesCommand extends Command
{
    public function __construct(
        private readonly RefreshTokenFamilyRepository $families,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $revoked = $this->families->revokeAllFamilies();

        $io = new SymfonyStyle($input, $output);
        $io->success(\sprintf('Revoked %d refresh-token %s. Clients must authorize again.', $revoked, 1 === $revoked ? 'family' : 'families'));

        return Command::SUCCESS;
    }
}
