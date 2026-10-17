<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Message\MailingSubscribe;
use App\MessageHandler\MailingSubscribeHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The container wiring for the newsletter handler: the handler resolves from
 * the container and its scoped client sends to the LISTMONK_URL base URI with
 * the configured API credentials and list id. The scoped client itself is
 * replaced by a MockHttpClient, so no test contacts a real listmonk.
 */
final class MailingSubscribeHandlerWiringTest extends KernelTestCase
{
    public function testHandlerSendsThroughTheConfiguredListmonkClient(): void
    {
        self::bootKernel();

        $requests = [];
        static::getContainer()->set(
            'listmonk.client',
            new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

                return new MockResponse('{"data":{"id":1}}', ['http_code' => 200]);
            })
        );

        $handler = static::getContainer()->get(MailingSubscribeHandler::class);
        self::assertInstanceOf(MailingSubscribeHandler::class, $handler);

        $handler(new MailingSubscribe('reader@example.com'));

        $baseUri = rtrim((string) ($_ENV['LISTMONK_URL'] ?? $_SERVER['LISTMONK_URL'] ?? ''), '/');
        $expectedAuth = sprintf(
            'token %s:%s',
            $_ENV['LISTMONK_API_USER'] ?? $_SERVER['LISTMONK_API_USER'] ?? '',
            $_ENV['LISTMONK_API_TOKEN'] ?? $_SERVER['LISTMONK_API_TOKEN'] ?? ''
        );
        $expectedListId = (int) ($_ENV['LISTMONK_LIST_ID'] ?? $_SERVER['LISTMONK_LIST_ID'] ?? 0);

        self::assertNotSame('', $baseUri);
        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame($baseUri.'/api/subscribers', $requests[0]['url']);

        $authorization = null;
        foreach ($requests[0]['options']['headers'] ?? [] as $header) {
            if (str_starts_with(strtolower($header), 'authorization:')) {
                $authorization = trim(substr($header, \strlen('authorization:')));
            }
        }
        self::assertSame($expectedAuth, $authorization);

        self::assertSame(
            ['email' => 'reader@example.com', 'status' => 'enabled', 'lists' => [$expectedListId]],
            json_decode($requests[0]['options']['body'] ?? '', true)
        );
    }
}
