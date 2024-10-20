<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'migrateToUuid',
    description: 'Add a short description for your command',
)]
class MigrateToUuidCommand extends Command
{
    private const TABLES = [
        'user',
        'reset_password_request',
        'subscription',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach (self::TABLES as $table) {
            $this->generateUuids($table);
        }

        return Command::SUCCESS;
    }

    private function generateUuids(string $table): void
    {
        $connection = $this->entityManager->getConnection();
        $query = $connection->executeQuery("SELECT id FROM paysub_v1.$table");
        $ids = $query->fetchAllAssociative();

        foreach ($ids as $id) {
            $uuid = Uuid::v7();
            $connection->executeQuery("UPDATE paysub_v1.$table SET uuid = :uuid WHERE id = :id", [
                'uuid' => $uuid->toBinary(),
                'id' => $id['id'],
            ]);
        }
    }
}
