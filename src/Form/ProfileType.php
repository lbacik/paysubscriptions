<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Service\CurrencyService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class ProfileType extends AbstractType
{
    public function __construct(
        private readonly CurrencyService $currencies,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('mainCurrency', ChoiceType::class, [
            'choices' => $this->currencies->getChoices(),
            'label' => 'Main currency',
            'help' => 'Totals are reported in this currency. Subscriptions in another currency need a converted amount entered by you.',
            'constraints' => [
                new NotBlank(message: 'Please select your main currency.'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
