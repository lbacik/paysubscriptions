<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\MailingSubscribe;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Subscribes a newsletter signup through the self-hosted listmonk API.
 *
 * The footer form queues MailingSubscribe on `async`; the worker POSTs the
 * address to listmonk, which owns the double opt-in from there (every signup
 * receives a confirmation email and is subscribed only after clicking it).
 *
 * Response classification (listmonk returns 200 on creation):
 * - 200: success.
 * - 409 (duplicate email): idempotent success — the existing subscriber is
 *   left untouched, so a blocklisted or unsubscribed address stays as it is.
 * - 429 / 5xx / network error / timeout: recoverable, the message is retried.
 * - 401 / 403: unrecoverable (retrying cannot fix credentials).
 * - any other 4xx: unrecoverable (retrying cannot fix the request).
 *
 * Privacy: the subscriber address never appears in a log message or context,
 * only the response status class does.
 */
#[AsMessageHandler]
final class MailingSubscribeHandler
{
    public function __construct(
        private readonly HttpClientInterface $listmonkClient,
        private readonly int $listmonkListId,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(MailingSubscribe $message): void
    {
        try {
            $status = $this->listmonkClient->request('POST', '/api/subscribers', [
                'json' => [
                    'email' => $message->email,
                    'status' => 'enabled',
                    'lists' => [$this->listmonkListId],
                ],
            ])->getStatusCode();
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Newsletter subscription failed: listmonk is unreachable.', [
                'error' => $exception::class,
            ]);

            throw new RecoverableMessageHandlingException('listmonk request failed; will retry.', 0, $exception);
        }

        if (200 === $status) {
            return;
        }

        if (409 === $status) {
            $this->logger->info('Newsletter subscription already exists; leaving the subscriber untouched.', [
                'status_code' => $status,
            ]);

            return;
        }

        if (401 === $status || 403 === $status) {
            $this->logger->error('Newsletter subscription rejected: check listmonk API credentials.', [
                'status_code' => $status,
            ]);

            throw new UnrecoverableMessageHandlingException(
                sprintf('listmonk rejected the subscription request with status %d.', $status)
            );
        }

        if (429 === $status || $status >= 500) {
            $this->logger->error('Newsletter subscription failed with a retryable listmonk response.', [
                'status_code' => $status,
            ]);

            throw new RecoverableMessageHandlingException(
                sprintf('listmonk answered with status %d; will retry.', $status)
            );
        }

        $this->logger->error('Newsletter subscription rejected by listmonk.', [
            'status_code' => $status,
        ]);

        throw new UnrecoverableMessageHandlingException(
            sprintf('listmonk rejected the subscription request with status %d.', $status)
        );
    }
}
