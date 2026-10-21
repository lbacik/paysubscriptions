<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Message\MailingSubscribe;
use App\MessageHandler\MailingSubscribeHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Newsletter subscription through the self-hosted listmonk API.
 *
 * The handler takes a scoped-client-shaped HttpClientInterface (base URI plus
 * the `Authorization: token USER:TOKEN` default header, as wired in
 * config/packages/framework.yaml) and POSTs the subscriber to
 * /api/subscribers. No test contacts a real listmonk: every response below
 * comes from MockHttpClient.
 */
final class MailingSubscribeHandlerTest extends TestCase
{
    private const BASE_URI = 'http://listmonk.test';

    private const AUTH_HEADER = 'token test-user:test-token';

    private const LIST_ID = 7;

    private const EMAIL = 'reader@example.com';

    /** @var list<array{method: string, url: string, headers: list<string>, body: string}> */
    private array $requests = [];

    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requests = [];
        $this->logs = new TestHandler();
    }

    public function testPostsTheSubscriberWithTheExactRequestShape(): void
    {
        $handler = $this->handler(new MockResponse('{"data":{"id":42}}', ['http_code' => 200]));

        $handler(new MailingSubscribe(self::EMAIL));

        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame(self::BASE_URI.'/api/subscribers', $request['url']);
        self::assertSame(self::AUTH_HEADER, $this->authorizationHeader($request['headers']));
        self::assertSame(
            ['email' => self::EMAIL, 'status' => 'enabled', 'lists' => [self::LIST_ID]],
            json_decode($request['body'], true)
        );
    }

    /**
     * @return iterable<string, array{int, class-string<\Throwable>|null}>
     */
    public static function responseClasses(): iterable
    {
        yield 'created' => [200, null];
        yield 'duplicate email' => [409, null];
        yield 'bad request' => [400, UnrecoverableMessageHandlingException::class];
        yield 'not found' => [404, UnrecoverableMessageHandlingException::class];
        yield 'unprocessable' => [422, UnrecoverableMessageHandlingException::class];
        yield 'unauthorized' => [401, UnrecoverableMessageHandlingException::class];
        yield 'forbidden' => [403, UnrecoverableMessageHandlingException::class];
        yield 'rate limited' => [429, RecoverableMessageHandlingException::class];
        yield 'server error' => [500, RecoverableMessageHandlingException::class];
        yield 'unavailable' => [503, RecoverableMessageHandlingException::class];
    }

    /**
     * @dataProvider responseClasses
     *
     * @param class-string<\Throwable>|null $expectedException
     */
    public function testClassifiesListmonkResponses(int $status, ?string $expectedException): void
    {
        $handler = $this->handler(new MockResponse('{}', ['http_code' => $status]));

        if (null !== $expectedException) {
            $this->expectException($expectedException);
        }

        $handler(new MailingSubscribe(self::EMAIL));

        // 200 and 409 return normally.
        self::assertCount(1, $this->requests);
        self::assertFalse($this->logs->hasRecords(Level::Error));
    }

    public function testDuplicateLogsAtInfoAndKeepsTheSubscriberUntouched(): void
    {
        $handler = $this->handler(new MockResponse('{"message":"duplicate"}', ['http_code' => 409]));

        $handler(new MailingSubscribe(self::EMAIL));

        // Exactly one request: no lookup and no re-add, so a blocklisted or
        // unsubscribed subscriber stays as it is.
        self::assertCount(1, $this->requests);
        self::assertTrue($this->logs->hasRecords(Level::Info));
        self::assertFalse($this->logs->hasRecords(Level::Error));
        $this->assertEmailAbsentFromLogs();
    }

    /**
     * @return iterable<string, array{int, class-string<\Throwable>}>
     */
    public static function failureResponses(): iterable
    {
        foreach (self::responseClasses() as $name => [$status, $expectedException]) {
            if (null !== $expectedException) {
                yield $name => [$status, $expectedException];
            }
        }
    }

    /**
     * @dataProvider failureResponses
     *
     * @param class-string<\Throwable> $expectedException
     */
    public function testFailuresLogTheStatusCodeWithoutTheEmail(int $status, string $expectedException): void
    {
        $handler = $this->handler(new MockResponse('{}', ['http_code' => $status]));

        try {
            $handler(new MailingSubscribe(self::EMAIL));
            self::fail(sprintf('Expected %s for status %d.', $expectedException, $status));
        } catch (\Throwable $exception) {
            self::assertInstanceOf($expectedException, $exception);
        }

        self::assertTrue($this->logs->hasRecords(Level::Error));
        $found = false;
        foreach ($this->logs->getRecords() as $record) {
            if (Level::Error !== $record->level) {
                continue;
            }
            $found = true;
            self::assertSame($status, $record->context['status_code'] ?? null);
        }
        self::assertTrue($found, 'Expected an error record carrying the status code.');
        $this->assertEmailAbsentFromLogs();
    }

    /**
     * @dataProvider authenticationFailures
     */
    public function testAuthenticationFailuresHintAtTheApiCredentials(int $status): void
    {
        $handler = $this->handler(new MockResponse('{}', ['http_code' => $status]));

        try {
            $handler(new MailingSubscribe(self::EMAIL));
            self::fail(sprintf('Expected an unrecoverable failure for status %d.', $status));
        } catch (UnrecoverableMessageHandlingException) {
            // Expected: retrying cannot fix credentials.
        }

        self::assertTrue(
            $this->logs->hasRecordThatContains('check listmonk API credentials', Level::Error),
            'Expected the credentials hint in an error record.'
        );
        $this->assertEmailAbsentFromLogs();
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function authenticationFailures(): iterable
    {
        yield 'unauthorized' => [401];
        yield 'forbidden' => [403];
    }

    public function testTransportErrorIsRecoverable(): void
    {
        $handler = $this->handler(new TransportException('Connection refused'));

        $this->expectException(RecoverableMessageHandlingException::class);

        try {
            $handler(new MailingSubscribe(self::EMAIL));
        } finally {
            self::assertTrue($this->logs->hasRecords(Level::Error));
            $this->assertEmailAbsentFromLogs();
        }
    }

    public function testTimeoutIsRecoverable(): void
    {
        $handler = $this->handler(new TimeoutException('Idle timeout reached'));

        $this->expectException(RecoverableMessageHandlingException::class);

        try {
            $handler(new MailingSubscribe(self::EMAIL));
        } finally {
            self::assertTrue($this->logs->hasRecords(Level::Error));
            $this->assertEmailAbsentFromLogs();
        }
    }

    /**
     * Builds the handler the way the container wires it: a client carrying
     * the base URI and the API credentials as defaults, the numeric list id,
     * and a logger observed through a Monolog TestHandler.
     *
     * @param MockResponse|\Throwable $responseFactory
     */
    private function handler(MockResponse|\Throwable $responseFactory, int $listId = self::LIST_ID): MailingSubscribeHandler
    {
        if ($responseFactory instanceof \Throwable) {
            $error = $responseFactory;
            $responseFactory = static function () use ($error): never {
                throw $error;
            };
        }

        $client = (new MockHttpClient(function (string $method, string $url, array $options) use ($responseFactory): MockResponse {
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => $options['headers'] ?? [],
                'body' => $options['body'] ?? '',
            ];

            return $responseFactory instanceof MockResponse
                ? $responseFactory
                : $responseFactory($method, $url, $options);
        }))->withOptions([
            'base_uri' => self::BASE_URI,
            'headers' => ['Authorization' => self::AUTH_HEADER],
        ]);

        return new MailingSubscribeHandler($client, $listId, new Logger('test', [$this->logs]));
    }

    /**
     * @param list<string> $headers
     */
    private function authorizationHeader(array $headers): ?string
    {
        foreach ($headers as $header) {
            if (str_starts_with(strtolower($header), 'authorization:')) {
                return trim(substr($header, \strlen('authorization:')));
            }
        }

        return null;
    }

    private function assertEmailAbsentFromLogs(): void
    {
        foreach ($this->logs->getRecords() as $record) {
            self::assertStringNotContainsString(
                self::EMAIL,
                $record->message,
                'The full subscriber address must never appear in a log message.'
            );
            self::assertStringNotContainsString(
                self::EMAIL,
                json_encode($record->context) ?: '',
                'The full subscriber address must never appear in a log context.'
            );
        }
    }
}
