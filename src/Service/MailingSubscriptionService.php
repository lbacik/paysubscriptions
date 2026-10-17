<?php

declare(strict_types=1);

namespace App\Service;

use App\Message\MailingSubscribe;
use Symfony\Component\Messenger\MessageBusInterface;

class MailingSubscriptionService
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function subscribe(string $email): void
    {
        // Routed to `async` (see config/packages/messenger.yaml): the worker's
        // MailingSubscribeHandler subscribes the address through the
        // self-hosted listmonk API, and listmonk sends its own opt-in mail.
        $this->messageBus->dispatch(new MailingSubscribe($email));
    }
}
