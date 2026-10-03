<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Repository\RecipientRestrictionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;

/**
 * Drops restricted recipients from every outgoing email.
 *
 * Runs when an email is queued and again when it is sent, so a restriction
 * recorded while the email waited in the queue still applies. An email left
 * with no recipient is rejected and never reaches the transport. Logs carry
 * counts only, never addresses.
 *
 * Registered above the test message logger (priority -255) so a rejected
 * email is not counted as sent.
 */
#[AsEventListener(priority: 100)]
final class RecipientRestrictionMailListener
{
    public function __construct(
        private readonly RecipientRestrictionRepository $restrictions,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(MessageEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $recipients = $envelope->getRecipients();
        $restricted = $this->restrictions->restrictedAmong(array_map(static fn ($address): string => $address->getAddress(), $recipients));
        if ([] === $restricted) {
            return;
        }

        $allowed = array_values(array_filter(
            $recipients,
            static fn ($address): bool => !\in_array(RecipientRestrictionRepository::normalize($address->getAddress()), $restricted, true),
        ));

        if ([] === $allowed) {
            $event->reject();
            $this->logger->info('Email not sent: every recipient is restricted by SES feedback.', ['restricted' => \count($restricted)]);

            return;
        }

        $envelope->setRecipients($allowed);
        $this->logger->info('Restricted recipients removed from email.', ['restricted' => \count($recipients) - \count($allowed), 'remaining' => \count($allowed)]);
    }
}
