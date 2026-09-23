<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $zones = \DateTimeZone::listIdentifiers();

        $builder
            ->add('emailRemindersEnabled', CheckboxType::class, [
                'required' => false,
                'label' => 'settings.email_enabled',
            ])
            ->add('reminderLeadDays', IntegerType::class, [
                'label' => 'settings.lead_days',
                'help' => 'settings.lead_days_help',
                'attr' => [
                    'min' => User::MIN_REMINDER_LEAD_DAYS,
                    'max' => User::MAX_REMINDER_LEAD_DAYS,
                ],
            ])
            ->add('timezone', ChoiceType::class, [
                'label' => 'settings.timezone',
                'help' => 'settings.timezone_help',
                'choices' => array_combine($zones, $zones),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
