<?php

declare(strict_types=1);

namespace App\Mailer;

use App\Enum\RecipientRestrictionState;
use App\Repository\RecipientRestrictionRepository;

/**
 * Applies one SES feedback event from the paysubs recipient-restriction queue.
 *
 * The queue receives only permanent bounces and complaints (aws-config
 * `paysubs-ses-restriction-*` rules), but every event is checked again here:
 * it must come from SES in the boundary account and Region, through the
 * paysubs-app configuration set, and name its affected recipients. Anything
 * else throws InvalidSesFeedback and changes nothing.
 *
 * Applying an event only ever tightens restrictions, so redelivery and
 * out-of-order events are harmless. No event verifies an address or touches
 * authentication state.
 */
final class SesRestrictionFeedback
{
    public function __construct(
        private readonly RecipientRestrictionRepository $restrictions,
    ) {
    }

    /**
     * @return int the number of recipients restricted (or confirmed restricted)
     *
     * @throws InvalidSesFeedback
     */
    public function apply(string $body): int
    {
        try {
            $event = json_decode($body, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidSesFeedback('The message is not JSON.');
        }
        if (!\is_array($event)) {
            throw new InvalidSesFeedback('The message is not an event object.');
        }

        if (SesTenantBoundary::ACCOUNT !== ($event['account'] ?? null) || SesTenantBoundary::REGION !== ($event['region'] ?? null) || 'aws.ses' !== ($event['source'] ?? null)) {
            throw new InvalidSesFeedback('The event does not come from SES in the boundary account and Region.');
        }

        $detail = $event['detail'] ?? null;
        $configurationSets = \is_array($detail) ? ($detail['mail']['tags']['ses:configuration-set'] ?? null) : null;
        if (!\is_array($configurationSets) || !\in_array(SesTenantBoundary::CONFIGURATION_SET, $configurationSets, true)) {
            throw new InvalidSesFeedback('The event does not belong to the paysubs-app configuration set.');
        }

        [$state, $recipients] = match ($event['detail-type'] ?? null) {
            'Email Bounced' => $this->permanentBounce($detail),
            'Email Complaint Received' => [RecipientRestrictionState::DoNotSend, $this->recipients($detail['complaint']['complainedRecipients'] ?? null)],
            default => throw new InvalidSesFeedback('The event is neither a bounce nor a complaint.'),
        };

        foreach ($recipients as $recipient) {
            $this->restrictions->restrict($recipient, $state);
        }

        return \count($recipients);
    }

    /**
     * @param array<mixed> $detail
     *
     * @return array{RecipientRestrictionState, list<string>}
     */
    private function permanentBounce(array $detail): array
    {
        if ('Permanent' !== ($detail['bounce']['bounceType'] ?? null)) {
            throw new InvalidSesFeedback('Only permanent bounces restrict a recipient.');
        }

        return [RecipientRestrictionState::Undeliverable, $this->recipients($detail['bounce']['bouncedRecipients'] ?? null)];
    }

    /**
     * @return list<string>
     */
    private function recipients(mixed $entries): array
    {
        if (!\is_array($entries) || [] === $entries) {
            throw new InvalidSesFeedback('The event names no affected recipient.');
        }

        $recipients = [];
        foreach ($entries as $entry) {
            $address = \is_array($entry) ? ($entry['emailAddress'] ?? null) : null;
            if (!\is_string($address) || false === filter_var($address, \FILTER_VALIDATE_EMAIL)) {
                throw new InvalidSesFeedback('The event names an invalid recipient.');
            }
            $recipients[] = $address;
        }

        return $recipients;
    }
}
