<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserTest extends KernelTestCase
{
    public function testReminderSettingsHaveExplicitDefaults(): void
    {
        $user = new User();

        self::assertSame('UTC', $user->getTimezone());
        self::assertFalse($user->isEmailRemindersEnabled());
        self::assertSame(3, $user->getReminderLeadDays());
    }

    public function testValidReminderSettingsPassValidation(): void
    {
        $user = (new User())
            ->setEmail('user@example.com')
            ->setPassword('hashed')
            ->setTimezone('Europe/Warsaw')
            ->setEmailRemindersEnabled(true)
            ->setReminderLeadDays(5);

        self::assertCount(0, $this->validator()->validate($user));
    }

    public function testInvalidTimezoneFailsValidation(): void
    {
        $user = (new User())
            ->setEmail('user@example.com')
            ->setPassword('hashed')
            ->setTimezone('Mars/Olympus');

        $violations = $this->validator()->validate($user);

        self::assertGreaterThan(0, \count($violations));
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }
        self::assertContains('timezone', $paths);
    }

    /**
     * @dataProvider invalidLeadDays
     */
    public function testOutOfRangeLeadDaysFailValidation(int $leadDays): void
    {
        $user = (new User())
            ->setEmail('user@example.com')
            ->setPassword('hashed')
            ->setReminderLeadDays($leadDays);

        $violations = $this->validator()->validate($user);

        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }
        self::assertContains('reminderLeadDays', $paths);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public function invalidLeadDays(): iterable
    {
        yield 'zero days' => [0];
        yield 'thirty-one days' => [31];
        yield 'negative' => [-1];
    }

    private function validator(): \Symfony\Component\Validator\Validator\ValidatorInterface
    {
        self::bootKernel();

        return static::getContainer()->get('validator');
    }
}
