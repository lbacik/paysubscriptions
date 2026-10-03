<?php

declare(strict_types=1);

namespace App\Mailer;

use AsyncAws\Core\Configuration;
use AsyncAws\Ses\SesClient;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds the tenant-aware SES transport from
 * `ses+tenant://ACCESS_KEY_ID:SECRET_ACCESS_KEY@default`.
 *
 * The DSN carries only the dedicated sending principal's credentials. A host,
 * port, or any option that would suggest choosing the endpoint, Region,
 * tenant or configuration set there is rejected rather than ignored.
 */
final class SesTenantTransportFactory extends AbstractTransportFactory
{
    private const REJECTED_OPTIONS = ['region', 'session_token', 'endpoint', 'tenant', 'tenant_name', 'configuration_set', 'ping_threshold'];

    public function create(Dsn $dsn): TransportInterface
    {
        if ('ses+tenant' !== $dsn->getScheme()) {
            throw new UnsupportedSchemeException($dsn, 'ses+tenant', $this->getSupportedSchemes());
        }

        $boundaryOptions = array_filter(self::REJECTED_OPTIONS, static fn (string $option): bool => null !== $dsn->getOption($option));
        if ('default' !== $dsn->getHost() || null !== $dsn->getPort() || [] !== $boundaryOptions) {
            throw new InvalidArgumentException('The ses+tenant DSN accepts only credentials: ses+tenant://ACCESS_KEY_ID:SECRET_ACCESS_KEY@default.');
        }

        $client = new SesClient(Configuration::create([
            'region' => SesTenantBoundary::REGION,
            'accessKeyId' => $this->getUser($dsn),
            'accessKeySecret' => $this->getPassword($dsn),
        ]), null, $this->client, $this->logger);

        return new SesTenantTransport($client, $this->dispatcher, $this->logger);
    }

    protected function getSupportedSchemes(): array
    {
        return ['ses+tenant'];
    }
}
