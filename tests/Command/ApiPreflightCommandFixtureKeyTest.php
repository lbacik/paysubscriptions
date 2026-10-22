<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ApiPreflightCommand;
use App\OAuth2\JwksProvider;
use Doctrine\DBAL\Connection;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * The release preflight must refuse the committed test-only OAuth keypair
 * outside the test environment (production image hardening): the fixture
 * keys are public, so configuring them in production would mint tokens
 * anyone can verify against — and the production image no longer ships
 * the tests directory at all.
 *
 * Pure unit test on purpose: it constructs the command directly with stub
 * collaborators, so it runs without a kernel, a database, or secrets.
 */
final class ApiPreflightCommandFixtureKeyTest extends TestCase
{
    private string $privateKeyPath;
    private string $publicKeyPath;
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $privatePem);
        $details = openssl_pkey_get_details($key);
        self::assertNotFalse($details);

        $this->privateKeyPath = $this->writeTempFile($privatePem);
        $this->publicKeyPath = $this->writeTempFile((string) $details['key']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testProdFailsWhenPrivateKeyIsTestFixture(): void
    {
        $tester = $this->runPreflight(
            '%kernel.project_dir%/tests/Fixtures/oauth/private.pem',
            $this->publicKeyPath,
            'prod',
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('[FAIL] oauth-private-key', $tester->getDisplay());
        self::assertStringContainsString('test fixture', $tester->getDisplay());
    }

    public function testProdFailsWhenPublicKeyIsTestFixtureInline(): void
    {
        $fixturePublic = file_get_contents(
            dirname(__DIR__, 2).'/tests/Fixtures/oauth/public.pem'
        );
        self::assertIsString($fixturePublic);

        $tester = $this->runPreflight(
            $this->privateKeyPath,
            $fixturePublic,
            'prod',
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('[FAIL] oauth-public-key', $tester->getDisplay());
        self::assertStringContainsString('test fixture', $tester->getDisplay());
    }

    public function testProdFailsWhenPreviousFixtureKeyIsConfigured(): void
    {
        $tester = $this->runPreflight(
            $this->privateKeyPath,
            '%kernel.project_dir%/tests/Fixtures/oauth/previous-public.pem',
            'prod',
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('[FAIL] oauth-public-key', $tester->getDisplay());
    }

    public function testProdPassesWithFreshKeys(): void
    {
        $tester = $this->runPreflight($this->privateKeyPath, $this->publicKeyPath, 'prod');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    public function testTestEnvironmentStillAcceptsFixtureKeys(): void
    {
        $tester = $this->runPreflight(
            '%kernel.project_dir%/tests/Fixtures/oauth/private.pem',
            '%kernel.project_dir%/tests/Fixtures/oauth/public.pem',
            'test',
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    public function testProdFailsWhenDocsClientIsMissing(): void
    {
        $tester = $this->runPreflight(
            $this->privateKeyPath,
            $this->publicKeyPath,
            'prod',
            null,
            false,
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('[FAIL] docs-client', $tester->getDisplay());
        self::assertStringContainsString(ApiPreflightCommand::DOCS_CLIENT_ID, $tester->getDisplay());
    }

    public function testProdFailsWhenDocsClientIsInactive(): void
    {
        $client = self::validDocsClient('https://paysubscriptions.com');
        $client->setActive(false);

        $tester = $this->runPreflight($this->privateKeyPath, $this->publicKeyPath, 'prod', $client, false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('[FAIL] docs-client', $tester->getDisplay());
    }

    public function testProdFailsWhenDocsClientLacksFullScope(): void
    {
        $client = new Client('PaySubscriptions API docs', ApiPreflightCommand::DOCS_CLIENT_ID, null);
        $client->setScopes(new Scope('api:read'));
        $client->setRedirectUris(new RedirectUri('https://paysubscriptions.com/bundles/apiplatform/swagger-ui/oauth2-redirect.html'));

        $tester = $this->runPreflight($this->privateKeyPath, $this->publicKeyPath, 'prod', $client, false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('[FAIL] docs-client', $tester->getDisplay());
        self::assertStringContainsString('api:full', $tester->getDisplay());
    }

    public function testProdFailsWhenDocsClientMissesRedirectUri(): void
    {
        $client = new Client('PaySubscriptions API docs', ApiPreflightCommand::DOCS_CLIENT_ID, null);
        $client->setScopes(new Scope('api:full'));
        $client->setRedirectUris(new RedirectUri('https://paysubscriptions.com/wrong-redirect.html'));

        $tester = $this->runPreflight($this->privateKeyPath, $this->publicKeyPath, 'prod', $client, false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('[FAIL] docs-client', $tester->getDisplay());
        self::assertStringContainsString('oauth2-redirect.html', $tester->getDisplay());
    }

    /**
     * Runs the preflight with a registered, active docs Client approved for
     * `api:full` unless $docsClient is given explicitly (null = missing).
     *
     * @param ?Client $docsClient Pass null to simulate a missing registration.
     */
    private function runPreflight(string $privateKey, string $publicKey, string $environment, ?Client $docsClient = null, bool $useDefaultDocsClient = true): CommandTester
    {
        $projectDir = dirname(__DIR__, 2);
        $privateKey = str_replace('%kernel.project_dir%', $projectDir, $privateKey);
        $publicKey = str_replace('%kernel.project_dir%', $projectDir, $publicKey);

        $routes = new RouteCollection();
        foreach ([
            'oauth_discovery_authorization_server',
            'oauth_discovery_protected_resource',
            'oauth_discovery_jwks',
            'api_doc',
        ] as $name) {
            $routes->add($name, new Route('/'.$name));
        }
        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($routes);

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(1);

        $issuer = 'https://paysubscriptions.com';
        $resolved = $useDefaultDocsClient && null === $docsClient ? self::validDocsClient($issuer) : $docsClient;
        $clients = $this->createStub(ClientManagerInterface::class);
        $clients->method('find')->willReturnMap([[ApiPreflightCommand::DOCS_CLIENT_ID, $resolved]]);

        $command = new ApiPreflightCommand(
            $router,
            new JwksProvider($publicKey),
            $connection,
            $projectDir,
            $privateKey,
            $publicKey,
            bin2hex(random_bytes(32)),
            $environment,
            $clients,
            $issuer,
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    private static function validDocsClient(string $issuer): Client
    {
        $client = new Client('PaySubscriptions API docs', ApiPreflightCommand::DOCS_CLIENT_ID, null);
        $client->setScopes(new Scope('api:full'));
        $client->setRedirectUris(new RedirectUri($issuer.'/bundles/apiplatform/swagger-ui/oauth2-redirect.html'));

        return $client;
    }

    private function writeTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'preflight-test-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
