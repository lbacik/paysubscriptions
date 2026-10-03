<?php

declare(strict_types=1);

namespace App\Tests\Mailer;

use App\Mailer\SesTenantBoundaryViolation;
use App\Mailer\SesTenantTransport;
use App\Mailer\SesTenantTransportFactory;
use AsyncAws\Core\Configuration;
use AsyncAws\Ses\SesClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The SES tenant boundary: every accepted email names the paysubs-app tenant
 * and configuration set, and nothing a caller puts in the message can choose
 * another tenant, configuration set or From identity.
 */
final class SesTenantTransportTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: array<string, mixed>}> */
    private array $requests = [];

    public function testSendsRawMessageThroughTheTenantAndConfigurationSet(): void
    {
        $transport = $this->transport(new MockResponse('{"MessageId":"ses-message-1"}'));

        $sent = $transport->send($this->email()->bcc('hidden@example.com'));

        self::assertNotNull($sent);
        self::assertSame('ses-message-1', $sent->getMessageId());
        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertStringEndsWith('/v2/email/outbound-emails', $request['url']);
        self::assertSame('no-reply@paysubscriptions.com', $request['body']['FromEmailAddress']);
        self::assertSame('paysubs-app', $request['body']['TenantName']);
        self::assertSame('paysubs-app-events', $request['body']['ConfigurationSetName']);
        self::assertSame(['user@example.com', 'hidden@example.com'], $request['body']['Destination']['ToAddresses']);
        $raw = base64_decode($request['body']['Content']['Raw']['Data'], true);
        self::assertIsString($raw);
        self::assertStringContainsString('Subject: Hello', $raw);
        self::assertStringContainsString('Reply-To: visitor@example.com', $raw);
        self::assertStringNotContainsString('hidden@example.com', $raw);
    }

    public function testMatchesTheBoundaryFromAddressCaseInsensitively(): void
    {
        $transport = $this->transport(new MockResponse('{"MessageId":"ses-message-1"}'));

        $transport->send($this->email()->from(new Address('No-Reply@PaySubscriptions.com', 'PaySubscriptions')));

        self::assertCount(1, $this->requests);
    }

    /**
     * @return iterable<string, array{Email}>
     */
    public static function boundaryViolations(): iterable
    {
        $base = static fn (): Email => (new Email())->to('user@example.com')->subject('Hello')->text('Body');

        yield 'foreign From' => [$base()->from('no-reply@lukaszbacik.com')];
        yield 'second From' => [$base()->from('no-reply@paysubscriptions.com', 'other@paysubscriptions.com')];
        yield 'foreign envelope sender' => [$base()->from('no-reply@paysubscriptions.com')->returnPath('bounce@example.com')];
        yield 'configuration-set header' => [self::withHeader($base()->from('no-reply@paysubscriptions.com'), 'X-SES-CONFIGURATION-SET', 'lbc-first-contact-events')];
        yield 'tenant header' => [self::withHeader($base()->from('no-reply@paysubscriptions.com'), 'x-ses-tenant', 'lbc-first-contact')];
        yield 'source-arn header' => [self::withHeader($base()->from('no-reply@paysubscriptions.com'), 'X-SES-SOURCE-ARN', 'arn:aws:ses:eu-central-1:045689588845:identity/lukaszbacik.com')];
    }

    /**
     * @dataProvider boundaryViolations
     */
    public function testRejectsBoundaryViolationsWithoutCallingSes(Email $email): void
    {
        $transport = $this->transport(new MockResponse('{"MessageId":"never"}'));

        try {
            $transport->send($email);
            self::fail('The boundary violation was sent.');
        } catch (SesTenantBoundaryViolation $violation) {
            self::assertInstanceOf(UnrecoverableExceptionInterface::class, $violation);
        }

        self::assertSame([], $this->requests);
    }

    public function testSurfacesSesRejectionAsTransportFailure(): void
    {
        $transport = $this->transport(new MockResponse(
            '{"message":"Email address is not verified."}',
            ['http_code' => 400, 'response_headers' => ['x-amzn-errortype' => 'MessageRejected']],
        ));

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('SES did not accept the email');

        $transport->send($this->email());
    }

    public function testFactoryBuildsTheTransportFromCredentialsOnly(): void
    {
        $transport = (new SesTenantTransportFactory())->create(Dsn::fromString('ses+tenant://AKIAEXAMPLE:secret@default'));

        self::assertInstanceOf(SesTenantTransport::class, $transport);
        self::assertSame('ses+tenant://paysubs-app@eu-central-1', (string) $transport);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedDsns(): iterable
    {
        yield 'region option' => ['ses+tenant://AKIAEXAMPLE:secret@default?region=us-east-1'];
        yield 'tenant option' => ['ses+tenant://AKIAEXAMPLE:secret@default?tenant=lbc-first-contact'];
        yield 'custom endpoint' => ['ses+tenant://AKIAEXAMPLE:secret@ses.example.com'];
    }

    /**
     * @dataProvider rejectedDsns
     */
    public function testFactoryRejectsBoundaryOptionsInTheDsn(string $dsn): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SesTenantTransportFactory())->create(Dsn::fromString($dsn));
    }

    public function testFactoryDoesNotHandleOtherSchemes(): void
    {
        $factory = new SesTenantTransportFactory();

        self::assertFalse($factory->supports(Dsn::fromString('ses+api://AKIAEXAMPLE:secret@default')));
        $this->expectException(UnsupportedSchemeException::class);
        $factory->create(Dsn::fromString('ses+api://AKIAEXAMPLE:secret@default'));
    }

    private function transport(MockResponse $response): SesTenantTransport
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR)];

            return $response;
        });

        return new SesTenantTransport(new SesClient(Configuration::create([
            'region' => 'eu-central-1',
            'accessKeyId' => 'AKIAEXAMPLE',
            'accessKeySecret' => 'secret',
        ]), null, $http));
    }

    private function email(): Email
    {
        return (new Email())
            ->from(new Address('no-reply@paysubscriptions.com', 'PaySubscriptions'))
            ->to('user@example.com')
            ->replyTo('visitor@example.com')
            ->subject('Hello')
            ->text('Body');
    }

    private static function withHeader(Email $email, string $name, string $value): Email
    {
        $email->getHeaders()->addTextHeader($name, $value);

        return $email;
    }
}
