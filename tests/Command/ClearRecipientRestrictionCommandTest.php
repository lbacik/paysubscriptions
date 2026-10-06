<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ClearRecipientRestrictionCommand;
use App\Enum\RecipientRestrictionState;
use App\Repository\RecipientRestrictionRepository;
use App\Tests\DatabaseTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Operator clearing of a recipient restriction (issue #189): the only way a
 * restriction is loosened, and every clear leaves an audit record.
 */
final class ClearRecipientRestrictionCommandTest extends DatabaseTestCase
{
    private RecipientRestrictionRepository $restrictions;

    private TestHandler $audit;

    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restrictions = static::getContainer()->get(RecipientRestrictionRepository::class);
        $this->audit = new TestHandler();
        $this->tester = new CommandTester(new ClearRecipientRestrictionCommand(
            $this->restrictions,
            new Logger('audit', [$this->audit]),
        ));
    }

    public function testClearsTheNormalizedAddressAndAuditsThePreviousState(): void
    {
        $this->restrictions->restrict('complaint@simulator.amazonses.com', RecipientRestrictionState::DoNotSend);
        $this->restrictions->restrict('bounce@simulator.amazonses.com', RecipientRestrictionState::Undeliverable);

        $status = $this->tester->execute([
            'email' => '  Complaint@Simulator.AmazonSES.com ',
            '--reason' => 'SES simulator validation (#173)',
            '--operator' => 'lukasz',
        ]);

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertNull($this->restrictions->findOneByEmail('complaint@simulator.amazonses.com'));
        self::assertSame(['bounce@simulator.amazonses.com'], $this->restrictions->restrictedAmong([
            'complaint@simulator.amazonses.com',
            'bounce@simulator.amazonses.com',
        ]));

        $records = $this->audit->getRecords();
        self::assertCount(1, $records);
        $context = $records[0]->context;
        self::assertSame('recipient_restriction.cleared', $context['action']);
        self::assertSame('complaint@simulator.amazonses.com', $context['email']);
        self::assertSame('do_not_send', $context['previous_state']);
        self::assertArrayHasKey('restricted_since', $context);
        self::assertArrayHasKey('last_tightened_at', $context);
        self::assertSame('SES simulator validation (#173)', $context['reason']);
        self::assertSame('lukasz', $context['operator']);
        self::assertArrayHasKey('process_user', $context);
    }

    public function testRefusesWithoutAReason(): void
    {
        $this->restrictions->restrict('user@example.com', RecipientRestrictionState::Undeliverable);

        foreach ([[], ['--reason' => "  \t "]] as $reason) {
            $status = $this->tester->execute(['email' => 'user@example.com', '--operator' => 'lukasz'] + $reason);

            self::assertSame(Command::INVALID, $status);
            self::assertStringContainsString('--reason', $this->tester->getDisplay());
        }

        self::assertNotNull($this->restrictions->findOneByEmail('user@example.com'));
        self::assertSame([], $this->audit->getRecords());
    }

    public function testRefusesWithoutAnOperator(): void
    {
        $this->restrictions->restrict('user@example.com', RecipientRestrictionState::Undeliverable);

        $status = $this->tester->execute(['email' => 'user@example.com', '--reason' => 'Mailbox fixed']);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('--operator', $this->tester->getDisplay());
        self::assertNotNull($this->restrictions->findOneByEmail('user@example.com'));
        self::assertSame([], $this->audit->getRecords());
    }

    public function testFailsForAnAddressWithoutARestriction(): void
    {
        $this->restrictions->restrict('other@example.com', RecipientRestrictionState::DoNotSend);

        $status = $this->tester->execute([
            'email' => 'unknown@example.com',
            '--reason' => 'Mailbox fixed',
            '--operator' => 'lukasz',
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No recipient restriction exists for unknown@example.com', $this->tester->getDisplay());
        self::assertNotNull($this->restrictions->findOneByEmail('other@example.com'));
        self::assertSame([], $this->audit->getRecords());
    }

    public function testKeepsTheRestrictionWhenTheAuditRecordCannotBeWritten(): void
    {
        $this->restrictions->restrict('user@example.com', RecipientRestrictionState::DoNotSend);
        $failingAudit = new Logger('audit', [new class extends TestHandler {
            protected function write(LogRecord $record): void
            {
                throw new \RuntimeException('Audit log unavailable.');
            }
        }]);
        $tester = new CommandTester(new ClearRecipientRestrictionCommand($this->restrictions, $failingAudit));

        try {
            $tester->execute(['email' => 'user@example.com', '--reason' => 'Mailbox fixed', '--operator' => 'lukasz']);
            self::fail('The audit failure must abort the clear.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Audit log unavailable.', $failure->getMessage());
        }

        $this->em->clear();
        self::assertSame(RecipientRestrictionState::DoNotSend, $this->restrictions->findOneByEmail('user@example.com')?->getState());
    }

    public function testTheRegisteredCommandClearsTheRestriction(): void
    {
        $this->restrictions->restrict('user@example.com', RecipientRestrictionState::Undeliverable);

        $tester = new CommandTester((new Application(static::$kernel))->find('app:ses:clear-recipient-restriction'));
        $status = $tester->execute([
            'email' => 'user@example.com',
            '--reason' => 'Mailbox fixed',
            '--operator' => 'lukasz',
        ]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertStringContainsString('Cleared the undeliverable restriction of user@example.com', $tester->getDisplay());
        self::assertNull($this->restrictions->findOneByEmail('user@example.com'));
    }
}
