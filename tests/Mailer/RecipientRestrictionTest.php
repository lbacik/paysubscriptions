<?php

declare(strict_types=1);

namespace App\Tests\Mailer;

use App\Command\ConsumeRecipientRestrictionsCommand;
use App\Enum\RecipientRestrictionState;
use App\Mailer\InvalidSesFeedback;
use App\Mailer\SesRestrictionFeedback;
use App\Repository\RecipientRestrictionRepository;
use App\Tests\DatabaseTestCase;
use AsyncAws\Sqs\SqsClient;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Recipient-deliverability profile: SES permanent bounces and complaints
 * restrict an address monotonically, and restricted addresses receive no mail.
 */
final class RecipientRestrictionTest extends DatabaseTestCase
{
    use MailerAssertionsTrait;

    private RecipientRestrictionRepository $restrictions;

    private SesRestrictionFeedback $feedback;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restrictions = static::getContainer()->get(RecipientRestrictionRepository::class);
        $this->feedback = static::getContainer()->get(SesRestrictionFeedback::class);
    }

    public function testPermanentBounceMakesTheAddressUndeliverable(): void
    {
        self::assertSame(1, $this->feedback->apply(self::bounce('Bounced.User@Example.com')));

        self::assertSame(RecipientRestrictionState::Undeliverable, $this->restrictions->findOneByEmail('bounced.user@example.com')?->getState());
    }

    public function testComplaintMakesTheAddressDoNotSend(): void
    {
        $this->feedback->apply(self::complaint('user@example.com'));

        self::assertSame(RecipientRestrictionState::DoNotSend, $this->restrictions->findOneByEmail('user@example.com')?->getState());
    }

    public function testComplaintTightensUndeliverableAndALaterBounceNeverLoosensIt(): void
    {
        $this->feedback->apply(self::bounce('user@example.com'));
        $this->feedback->apply(self::complaint('user@example.com'));
        $this->feedback->apply(self::bounce('user@example.com'));
        $this->feedback->apply(self::complaint('user@example.com'));

        self::assertSame(RecipientRestrictionState::DoNotSend, $this->restrictions->findOneByEmail('user@example.com')?->getState());
        self::assertSame(['user@example.com'], $this->restrictions->restrictedAmong(['USER@example.com', 'other@example.com']));
    }

    public function testUpdatedAtMovesOnlyWhenTheStateEscalates(): void
    {
        $connection = static::getContainer()->get(Connection::class);
        $backdate = static fn () => $connection->executeStatement("UPDATE recipient_restriction SET updated_at = '2000-01-01 00:00:00'");
        $updatedAt = static fn (): string => (string) $connection->fetchOne('SELECT updated_at FROM recipient_restriction');

        $this->restrictions->restrict('user@example.com', RecipientRestrictionState::Undeliverable);
        $backdate();
        $this->restrictions->restrict('user@example.com', RecipientRestrictionState::Undeliverable);
        self::assertSame('2000-01-01 00:00:00', $updatedAt(), 'A repeated state leaves the row untouched.');

        $this->restrictions->restrict('user@example.com', RecipientRestrictionState::DoNotSend);
        self::assertNotSame('2000-01-01 00:00:00', $updatedAt(), 'Escalating to DoNotSend records when it happened.');

        $backdate();
        $this->restrictions->restrict('user@example.com', RecipientRestrictionState::Undeliverable);
        $this->restrictions->restrict('user@example.com', RecipientRestrictionState::DoNotSend);
        self::assertSame('2000-01-01 00:00:00', $updatedAt(), 'Nothing changes a DoNotSend row.');
        self::assertSame(RecipientRestrictionState::DoNotSend->value, $connection->fetchOne('SELECT state FROM recipient_restriction'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEvents(): iterable
    {
        $bounce = json_decode(self::bounce('user@example.com'), true);

        yield 'not JSON' => ['not json'];
        yield 'transient bounce' => [self::bounce('user@example.com', 'Transient')];
        yield 'delivery' => [json_encode(['detail-type' => 'Email Delivered'] + $bounce)];
        yield 'foreign account' => [json_encode(['account' => '999999999999'] + $bounce)];
        yield 'foreign region' => [json_encode(['region' => 'us-east-1'] + $bounce)];
        yield 'foreign configuration set' => [str_replace('paysubs-app-events', 'lbc-first-contact-events', self::bounce('user@example.com'))];
        yield 'no recipient' => [str_replace('"bouncedRecipients":[{"emailAddress":"user@example.com"}]', '"bouncedRecipients":[]', self::bounce('user@example.com'))];
        yield 'invalid recipient' => [self::bounce('not-an-address')];
    }

    /**
     * @dataProvider invalidEvents
     */
    public function testInvalidEventsChangeNothing(string $body): void
    {
        try {
            $this->feedback->apply($body);
            self::fail('The invalid event was applied.');
        } catch (InvalidSesFeedback) {
        }

        self::assertSame([], $this->restrictions->restrictedAmong(['user@example.com', 'not-an-address']));
    }

    public function testRestrictedRecipientReceivesNoMail(): void
    {
        $this->restrictions->restrict('blocked@example.com', RecipientRestrictionState::Undeliverable);

        static::getContainer()->get(MailerInterface::class)->send(self::email('Blocked@Example.com'));

        self::assertEmailCount(0);
    }

    public function testRestrictedRecipientIsDroppedWhileOthersStillReceiveTheEmail(): void
    {
        $this->restrictions->restrict('blocked@example.com', RecipientRestrictionState::DoNotSend);

        static::getContainer()->get(MailerInterface::class)->send(self::email('ok@example.com')->addCc('blocked@example.com'));

        self::assertEmailCount(1);
        $recipients = array_map(static fn ($address): string => $address->getAddress(), self::getMailerEvent(0)->getEnvelope()->getRecipients());
        self::assertSame(['ok@example.com'], $recipients);
    }

    public function testConsumerDeletesOnlyAppliedMessages(): void
    {
        $deleted = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$deleted): MockResponse {
            $target = self::header($options, 'x-amz-target');
            if ('AmazonSQS.ReceiveMessage' === $target) {
                return new MockResponse(json_encode(['Messages' => [
                    ['MessageId' => 'applied', 'ReceiptHandle' => 'receipt-applied', 'Body' => self::complaint('user@example.com')],
                    ['MessageId' => 'foreign', 'ReceiptHandle' => 'receipt-foreign', 'Body' => str_replace('paysubs-app-events', 'lbc-first-contact-events', self::bounce('other@example.com'))],
                ]]));
            }
            self::assertSame('AmazonSQS.DeleteMessage', $target);
            $deleted[] = json_decode($options['body'], true)['ReceiptHandle'];

            return new MockResponse('{}');
        });
        $sqs = new SqsClient(['region' => 'eu-central-1', 'accessKeyId' => 'AKIAEXAMPLE', 'accessKeySecret' => 'secret'], null, $http);
        $tester = new CommandTester(new ConsumeRecipientRestrictionsCommand($sqs, $this->feedback, new NullLogger(), 'https://sqs.eu-central-1.amazonaws.com/045689588845/paysubs-ses-recipient-restrictions'));

        self::assertSame(0, $tester->execute(['--once' => true]));

        self::assertStringContainsString('applied: 1 rejected: 1 retry: 0', $tester->getDisplay());
        self::assertSame(['receipt-applied'], $deleted);
        self::assertSame(['user@example.com'], $this->restrictions->restrictedAmong(['user@example.com', 'other@example.com']));
    }

    private static function bounce(string $recipient, string $type = 'Permanent'): string
    {
        return self::event('Email Bounced', ['bounce' => ['bounceType' => $type, 'bouncedRecipients' => [['emailAddress' => $recipient]]]]);
    }

    private static function complaint(string $recipient): string
    {
        return self::event('Email Complaint Received', ['complaint' => ['complainedRecipients' => [['emailAddress' => $recipient]]]]);
    }

    /**
     * @param array<string, mixed> $detail
     */
    private static function event(string $detailType, array $detail): string
    {
        return json_encode([
            'account' => '045689588845',
            'region' => 'eu-central-1',
            'source' => 'aws.ses',
            'detail-type' => $detailType,
            'detail' => $detail + ['mail' => ['tags' => ['ses:configuration-set' => ['paysubs-app-events']]]],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    private static function email(string $to): Email
    {
        return (new Email())->from('no-reply@paysubscriptions.com')->to($to)->subject('Hello')->text('Body');
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function header(array $options, string $name): ?string
    {
        foreach ($options['headers'] ?? [] as $header) {
            [$key, $value] = array_map('trim', explode(':', (string) $header, 2));
            if (0 === strcasecmp($key, $name)) {
                return $value;
            }
        }

        return null;
    }
}
