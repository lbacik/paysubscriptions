<?php

declare(strict_types=1);

namespace App\Command;

use App\OAuth2\JwksProvider;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouterInterface;

/**
 * Read-only release preflight for API v1 (issue #100).
 *
 * Verifies, without issuing tokens or touching User data, that a deployment
 * can serve the OAuth2 discovery documents, the versioned OpenAPI contract,
 * and database-backed traffic: key material is configured and readable, the
 * JWKS builds exactly one RSA verification key, the discovery and contract
 * routes exist, the committed contract parses at the expected version, and
 * the database answers.
 *
 * Operators run this after every deploy (and CI runs it as a smoke check).
 * Authenticated flows — token issuance, read, refresh, revocation — are
 * covered by the blocking PHPUnit suite, not here: they need a provisioned
 * client and User, which this command deliberately never creates.
 *
 * Never prints secret values: only configured yes/no, paths, key counts,
 * and the public JWKS usage markers.
 */
#[AsCommand(
    name: 'app:api:preflight',
    description: 'Verify API v1 release readiness: keys, discovery, contract, database',
)]
final class ApiPreflightCommand extends Command
{
    public const CONTRACT_VERSION = '1.0.0';

    /** @var list<string> */
    private const DISCOVERY_ROUTES = [
        'oauth_discovery_authorization_server',
        'oauth_discovery_protected_resource',
        'oauth_discovery_jwks',
        'api_v1_openapi',
    ];

    public function __construct(
        private readonly RouterInterface $router,
        private readonly JwksProvider $jwksProvider,
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(resolve:OAUTH_PRIVATE_KEY)%')]
        private readonly string $privateKey,
        #[Autowire('%env(resolve:OAUTH_PUBLIC_KEY)%')]
        private readonly string $publicKey,
        #[Autowire('%env(resolve:OAUTH_ENCRYPTION_KEY)%')]
        private readonly string $encryptionKey,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $failures = 0;

        $report = static function (string $check, bool $ok, string $detail) use ($io, &$failures): void {
            if (!$ok) {
                ++$failures;
            }
            $io->writeln(sprintf('[%s] %s: %s', $ok ? 'ok' : 'FAIL', $check, $detail));
        };

        foreach (['oauth-private-key' => [$this->privateKey, 'signing key'], 'oauth-public-key' => [$this->publicKey, 'verification key']] as $check => [$value, $label]) {
            [$ok, $detail] = self::assessKey($value, $label);
            $report($check, $ok, $detail);
        }
        $report(
            'oauth-encryption-key',
            '' !== trim($this->encryptionKey),
            '' !== trim($this->encryptionKey) ? 'refresh-token/code encryption key is configured' : 'OAUTH_ENCRYPTION_KEY is empty; token issuance and refresh cannot work'
        );

        try {
            $jwks = $this->jwksProvider->getJwks();
            $keys = $jwks['keys'] ?? [];
            $singleRsa = 1 === \count($keys)
                && 'RSA' === ($keys[0]['kty'] ?? null)
                && 'RS256' === ($keys[0]['alg'] ?? null)
                && 'sig' === ($keys[0]['use'] ?? null);
            $report('jwks', $singleRsa, sprintf('%d RSA/RS256/sig verification key(s) published', \count($keys)));
        } catch (\Throwable $e) {
            $report('jwks', false, 'verification key unreadable ('.$e->getMessage().')');
        }

        $routes = $this->router->getRouteCollection();
        $missingRoutes = array_values(array_filter(
            self::DISCOVERY_ROUTES,
            static fn(string $name): bool => null === $routes->get($name)
        ));
        $report(
            'discovery-routes',
            [] === $missingRoutes,
            [] === $missingRoutes
                ? 'authorization-server, protected-resource, jwks, and openapi routes are registered'
                : 'missing routes: '.implode(', ', $missingRoutes)
        );

        $contractPath = $this->projectDir.'/resources/openapi/v1.json';
        $contract = json_decode(@file_get_contents($contractPath) ?: '', true);
        $contractOk = \is_array($contract)
            && '3.0.3' === ($contract['openapi'] ?? null)
            && self::CONTRACT_VERSION === ($contract['info']['version'] ?? null);
        $report(
            'openapi-contract',
            $contractOk,
            $contractOk
                ? sprintf('v1.json parses at contract version %s', self::CONTRACT_VERSION)
                : 'resources/openapi/v1.json is unreadable or not at contract version '.self::CONTRACT_VERSION
        );

        try {
            $this->connection->fetchOne('SELECT 1');
            $report('database', true, 'database answers');
        } catch (\Throwable $e) {
            $report('database', false, 'database unreachable ('.$e->getMessage().')');
        }

        if ($failures > 0) {
            $io->error(sprintf('API v1 preflight failed with %d failing check(s).', $failures));

            return Command::FAILURE;
        }

        $io->success('API v1 preflight passed.');

        return Command::SUCCESS;
    }

    /**
     * Assesses key configuration without ever printing key material: paths
     * stay paths, inline values collapse to a configured marker. A
     * configured-but-unreadable path fails: the OAuth2 endpoints cannot work
     * with it.
     *
     * @return array{bool, string}
     */
    private static function assessKey(string $value, string $label): array
    {
        $value = trim($value);
        if ('' === $value) {
            return [false, sprintf('%s is not configured; OAuth2 endpoints cannot work', $label)];
        }

        if (str_starts_with($value, '-----BEGIN')) {
            return [true, sprintf('%s is configured inline', $label)];
        }

        $path = $value;
        if (str_starts_with($path, 'file://')) {
            $path = substr($path, \strlen('file://'));
        }

        return is_readable($path)
            ? [true, sprintf('%s is configured at a readable path', $label)]
            : [false, sprintf('%s path is not readable: %s', $label, $path)];
    }
}
