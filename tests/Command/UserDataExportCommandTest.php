<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\UserDataExportCommand;
use App\Enum\BillingCycle;
use App\Tests\DatabaseTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Operator fulfillment entry point for manual-request exports (issue #48).
 */
final class UserDataExportCommandTest extends DatabaseTestCase
{
    public function testExportOutputsOnlyTheRequestedUsersDataAsJson(): void
    {
        $user = $this->createUser('fulfill@example.com');
        $other = $this->createUser('stranger@example.com');
        $this->createSubscription($user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-02-10'));
        $this->createSubscription($other, 'Stranger Sub');

        $tester = new CommandTester(static::getContainer()->get(UserDataExportCommand::class));
        $status = $tester->execute(['user' => 'fulfill@example.com']);

        self::assertSame(Command::SUCCESS, $status);
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertSame('fulfill@example.com', $decoded['account']['email']);
        $names = array_column($decoded['subscriptions'], 'name');
        self::assertContains('Netflix', $names);
        self::assertNotContains('Stranger Sub', $names);
        self::assertStringNotContainsStringIgnoringCase('password', $tester->getDisplay());
    }

    public function testExportFailsForAnUnknownAddress(): void
    {
        $tester = new CommandTester(static::getContainer()->get(UserDataExportCommand::class));
        $status = $tester->execute(['user' => 'unknown@example.com']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('User not found', $tester->getDisplay());
    }

    public function testExportCanWriteToAFile(): void
    {
        $user = $this->createUser('file@example.com');
        $this->createSubscription($user, 'Netflix');

        $path = sys_get_temp_dir() . '/paysub-export-' . uniqid() . '.json';
        try {
            $tester = new CommandTester(static::getContainer()->get(UserDataExportCommand::class));
            $status = $tester->execute(['user' => 'file@example.com', '--output' => $path]);

            self::assertSame(Command::SUCCESS, $status);
            self::assertFileExists($path);
            $decoded = json_decode((string) file_get_contents($path), true);
            self::assertSame('file@example.com', $decoded['account']['email']);
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
}
