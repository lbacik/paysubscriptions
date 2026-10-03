<?php

declare(strict_types=1);

namespace App\Mailer;

use AsyncAws\Core\Exception\Http\HttpException;
use AsyncAws\Core\Exception\Http\NetworkException;
use AsyncAws\Ses\Input\SendEmailRequest;
use AsyncAws\Ses\SesClient;
use AsyncAws\Ses\ValueObject\Destination;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;

/**
 * Tenant-aware SES v2 transport for every PaySubscriptions email.
 *
 * Sends the rendered MIME message as raw content and always supplies the
 * boundary's tenant and configuration set. `symfony/amazon-mailer` cannot send
 * `TenantName` and lets `X-SES-*` headers choose the configuration set and
 * source identity, so it does not satisfy the boundary.
 *
 * A message whose From or envelope sender is not the boundary identity, or
 * that carries any `X-SES-*` header, is rejected before SES is called. The
 * IAM policy enforces the same limits independently.
 */
final class SesTenantTransport extends AbstractTransport
{
    public function __construct(
        private readonly SesClient $sesClient,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);
    }

    public function __toString(): string
    {
        return \sprintf('ses+tenant://%s@%s', SesTenantBoundary::TENANT_NAME, SesTenantBoundary::REGION);
    }

    protected function doSend(SentMessage $message): void
    {
        $request = $this->request($message);

        $result = $this->sesClient->sendEmail($request);
        $response = $result->info()['response'];

        try {
            $messageId = $result->getMessageId();
        } catch (HttpException $e) {
            throw new HttpTransportException(\sprintf('SES did not accept the email: %s (code %s).', $e->getAwsMessage() ?: $e->getMessage(), $e->getAwsCode() ?: $e->getCode()), $e->getResponse(), $e->getCode(), $e);
        } catch (NetworkException $e) {
            throw new HttpTransportException('Could not reach the Amazon SES API.', $response, 0, $e);
        }

        if (null === $messageId || '' === $messageId) {
            throw new HttpTransportException('SES returned no message ID.', $response);
        }

        $message->setMessageId($messageId);
    }

    private function request(SentMessage $message): SendEmailRequest
    {
        $original = $message->getOriginalMessage();
        if (!$original instanceof Message) {
            throw new SesTenantBoundaryViolation('Only MIME messages with headers can be sent through the SES tenant boundary.');
        }

        $from = $original->getHeaders()->getHeaderBody('From') ?? [];
        if (1 !== \count($from) || !$this->isBoundaryAddress($from[0])) {
            throw new SesTenantBoundaryViolation(\sprintf('The From header must be exactly %s.', SesTenantBoundary::FROM_ADDRESS));
        }

        if (!$this->isBoundaryAddress($message->getEnvelope()->getSender())) {
            throw new SesTenantBoundaryViolation(\sprintf('The envelope sender must be %s.', SesTenantBoundary::FROM_ADDRESS));
        }

        foreach ($original->getHeaders()->all() as $header) {
            if (str_starts_with(strtolower($header->getName()), 'x-ses-')) {
                throw new SesTenantBoundaryViolation(\sprintf('The %s header cannot override the SES tenant boundary.', $header->getName()));
            }
        }

        return new SendEmailRequest([
            'FromEmailAddress' => SesTenantBoundary::FROM_ADDRESS,
            // The envelope still lists Bcc recipients, which the rendered headers no longer carry.
            'Destination' => new Destination(['ToAddresses' => $this->stringifyAddresses($message->getEnvelope()->getRecipients())]),
            'Content' => ['Raw' => ['Data' => $message->toString()]],
            'ConfigurationSetName' => SesTenantBoundary::CONFIGURATION_SET,
            'TenantName' => SesTenantBoundary::TENANT_NAME,
        ]);
    }

    private function isBoundaryAddress(Address $address): bool
    {
        return 0 === strcasecmp($address->getAddress(), SesTenantBoundary::FROM_ADDRESS);
    }
}
