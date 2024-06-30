<?php

declare(strict_types=1);

namespace App\Service;

use App\Message\MailingSubscribe;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\MessageBusInterface;

class MailingSubscriptionService
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly string $jsonHubProjectUuid,
        private readonly string $mailingProviderRoutingKey,
    ) {
    }

    public function subscribe(string $email): void
    {
        $this->messageBus->dispatch(
            new MailingSubscribe($email, $this->jsonHubProjectUuid),
            [
                new AmqpStamp(routingKey: $this->mailingProviderRoutingKey),
            ],
        );
    }
}
