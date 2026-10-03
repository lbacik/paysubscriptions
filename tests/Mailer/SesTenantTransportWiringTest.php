<?php

declare(strict_types=1);

namespace App\Tests\Mailer;

use App\Mailer\SesTenantTransport;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport;

/**
 * MAILER_DSN=ses+tenant://… resolves to the tenant-aware transport, and the
 * stock SES schemes, which cannot carry the tenant, no longer resolve.
 */
final class SesTenantTransportWiringTest extends KernelTestCase
{
    public function testTenantDsnResolvesToTheTenantTransport(): void
    {
        self::assertInstanceOf(SesTenantTransport::class, $this->transports()->fromString('ses+tenant://AKIAEXAMPLE:secret@default'));
    }

    public function testStockSesApiSchemeIsNotAvailable(): void
    {
        $this->expectException(UnsupportedSchemeException::class);

        $this->transports()->fromString('ses+api://AKIAEXAMPLE:secret@default?region=eu-central-1');
    }

    private function transports(): Transport
    {
        $transports = self::getContainer()->get('mailer.transport_factory');
        \assert($transports instanceof Transport);

        return $transports;
    }
}
