<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ApiPreflightCommand;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
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

        // The docs Client lives in the database, which the preflight only
        // reads: register the deployment's docs Client for this run (no
        // fixtures — only the tests that need it create it, per #258).
        $issuer = (string) static::getContainer()->getParameter('app.oauth2.issuer');
        $manager = static::getContainer()->get(ClientManagerInterface::class);
        $docsClient = new Client('PaySubscriptions API docs', ApiPreflightCommand::DOCS_CLIENT_ID, null);
        $docsClient->setScopes(new Scope('api:full'));
        $docsClient->setRedirectUris(new RedirectUri(rtrim($issuer, '/').'/bundles/apiplatform/swagger-ui/oauth2-redirect.html'));
        $manager->save($docsClient);

        $tester = new CommandTester(static::getContainer()->get(ApiPreflightCommand::class));

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $display = $tester->getDisplay();
        foreach (['oauth-private-key', 'oauth-public-key', 'oauth-encryption-key', 'jwks', 'discovery-routes', 'openapi-contract', 'docs-client', 'database'] as $check) {
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
