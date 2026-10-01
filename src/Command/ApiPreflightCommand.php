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

    /**
     * SHA-256 fingerprints of the RSA moduli of the committed test-only
     * keypairs under tests/Fixtures/oauth. The modulus identifies the
     * keypair, so each fingerprint covers both halves: the first covers
     * private.pem + public.pem, the second previous-private.pem +
     * previous-public.pem.
     *
     * Stored as hashes rather than read from the fixture files because the
     * production image no longer ships the tests directory — the check has
     * to work where the fixtures are absent.
     *
     * @var list<string>
     */
    private const TEST_FIXTURE_KEY_FINGERPRINTS = [
        '4312aac9206e265158dd01cbbfba49218a78e4102b1379b24d6f047a557f8959',
        'de8c49d4f3da6e4390fad0827967d36df3da6b53594b88069e78e0259f4eee19',
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
        // Fail closed: an unwired environment is treated as production, so
        // the fixture-key refusal below stays on. Only the test
        // environment — the one place the fixtures are legitimately
        // configured (see the when@test overrides) — is exempt.
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'prod',
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
            if ($ok && 'test' !== $this->environment && self::isTestFixtureKey($value)) {
                $ok = false;
                $detail = sprintf(
                    '%s matches a committed test fixture key (tests/Fixtures/oauth) and must never be used outside the suite; generate production key material — see docs/api-operations.md §6',
                    $label
                );
            }
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

        $path = self::keyFilePath($value);

        return is_readable($path)
            ? [true, sprintf('%s is configured at a readable path', $label)]
            : [false, sprintf('%s path is not readable: %s', $label, $path)];
    }

    /**
     * Whether the configured key value is one of the committed test-only
     * fixture keys: compares the SHA-256 fingerprint of the key's RSA
     * modulus against the known fixture fingerprints, so file paths, file://
     * URLs, and inline PEM all match the same pair. Anything unresolvable
     * or unparseable returns false — readability is assessKey's job; this
     * only ever rejects positive matches.
     */
    private static function isTestFixtureKey(string $value): bool
    {
        $contents = self::readKeyContents($value);
        if (null === $contents) {
            return false;
        }

        $key = @openssl_pkey_get_private($contents);
        if (false === $key) {
            $key = @openssl_pkey_get_public($contents);
        }
        if (false === $key) {
            return false;
        }

        $details = openssl_pkey_get_details($key);
        if (false === $details || !isset($details['rsa']['n'])) {
            return false;
        }

        return \in_array(hash('sha256', $details['rsa']['n']), self::TEST_FIXTURE_KEY_FINGERPRINTS, true);
    }

    private static function readKeyContents(string $value): ?string
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        if (str_starts_with($value, '-----BEGIN')) {
            return $value;
        }

        $contents = @file_get_contents(self::keyFilePath($value));

        return \is_string($contents) && '' !== $contents ? $contents : null;
    }

    /**
     * Strips the optional file:// scheme the bundle's own key settings
     * accept, leaving the filesystem path both assessKey and
     * readKeyContents resolve.
     */
    private static function keyFilePath(string $value): string
    {
        if (str_starts_with($value, 'file://')) {
            return substr($value, \strlen('file://'));
        }

        return $value;
    }
}
