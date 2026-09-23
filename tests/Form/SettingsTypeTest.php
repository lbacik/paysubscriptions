<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\User;
use App\Form\SettingsType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class SettingsTypeTest extends KernelTestCase
{
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = static::getContainer()->get('form.factory');
    }

    public function testSubmitValidSettingsMapsToUser(): void
    {
        $user = new User();
        $form = $this->factory->create(SettingsType::class, $user, ['csrf_protection' => false]);

        $form->submit([
            'timezone' => 'Europe/Warsaw',
            'emailRemindersEnabled' => '1',
            'reminderLeadDays' => '5',
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame('Europe/Warsaw', $user->getTimezone());
        self::assertTrue($user->isEmailRemindersEnabled());
        self::assertSame(5, $user->getReminderLeadDays());
    }

    public function testDisablingEmailRemindersKeepsOtherSettings(): void
    {
        $user = (new User())
            ->setTimezone('America/New_York')
            ->setReminderLeadDays(7);
        $form = $this->factory->create(SettingsType::class, $user, ['csrf_protection' => false]);

        $form->submit([
            'timezone' => 'America/New_York',
            // an unchecked checkbox is absent from real browser payloads,
            // which the form maps to false
            'reminderLeadDays' => '7',
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertFalse($user->isEmailRemindersEnabled());
        self::assertSame('America/New_York', $user->getTimezone());
        self::assertSame(7, $user->getReminderLeadDays());
    }

    public function testInvalidTimezoneIsRejected(): void
    {
        $user = new User();
        $form = $this->factory->create(SettingsType::class, $user, ['csrf_protection' => false]);

        $form->submit([
            'timezone' => 'Mars/Olympus',
            'emailRemindersEnabled' => '',
            'reminderLeadDays' => '3',
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertFalse($form->isValid());
        self::assertTrue($form->get('timezone')->getErrors()->count() > 0);
    }

    /**
     * @dataProvider invalidLeadDays
     */
    public function testOutOfRangeLeadTimeIsRejected(string $leadDays): void
    {
        $user = new User();
        $form = $this->factory->create(SettingsType::class, $user, ['csrf_protection' => false]);

        $form->submit([
            'timezone' => 'UTC',
            'emailRemindersEnabled' => '1',
            'reminderLeadDays' => $leadDays,
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertFalse($form->isValid());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function invalidLeadDays(): iterable
    {
        yield 'zero days' => ['0'];
        yield 'thirty-one days' => ['31'];
    }
}
