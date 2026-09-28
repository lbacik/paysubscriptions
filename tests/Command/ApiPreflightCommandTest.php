<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ApiPreflightCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The release preflight reports readiness only: it passes in the test
 * environment and never prints secret material (issue #100).
 */
final class ApiPreflightCommandTest extends KernelTestCase
{
    public function testPreflightPassesAndLogsNoSecrets(): void
    {
        self::bootKernel();
        $tester = new CommandTester(static::getContainer()->get(ApiPreflightCommand::class));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        foreach (['oauth-private-key', 'oauth-public-key', 'oauth-encryption-key', 'jwks', 'discovery-routes', 'openapi-contract', 'database'] as $check) {
            self::assertStringContainsString('[ok] '.$check, $display);
        }

        // The command must never log key material, even though the test
        // environment configures real (dummy) values for every secret.
        foreach (['OAUTH_ENCRYPTION_KEY', 'OAUTH_PASSPHRASE'] as $name) {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
            if (\is_string($value) && '' !== $value) {
                self::assertStringNotContainsString($value, $display);
            }
        }
        self::assertStringNotContainsString('-----BEGIN', $display);
    }
}
