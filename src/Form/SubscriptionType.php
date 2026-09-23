<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ExpenseCategory;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use App\Service\CurrencyService;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SubscriptionType extends AbstractType
{
    public function __construct(
        private readonly CurrencyService $currencies,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User|null $user */
        $user = $options['user'];
        $mainCurrency = CurrencyService::normalizeCode($options['main_currency']);

        $builder
            ->add('name', TextType::class, [
                'attr' => [
                    'autofocus' => true,
                    'novalidate' => 'novalidate',
                ],

            ])
            ->add('billingCycle', EnumType::class, [
                'class' => BillingCycle::class,
                'label' => 'subscription.billing_cycle',
                'choice_label' => fn(BillingCycle $cycle) => 'subscription.billing_cycle_'.$cycle->value,
            ])
            ->add('amount', NumberType::class, [
                'label' => 'subscription.amount',
                'html5' => true,
                'attr' => [
                    'step' => 0.01,
                    'min' => 0,
                ],
            ])
            ->add('nextPayment', null, [
                'widget' => 'single_text',
                'label' => 'subscription.next_payment',
            ])
            ->add('category', EntityType::class, [
                'class' => ExpenseCategory::class,
                'choices' => $user?->getExpenseCategories() ?? [],
                'choice_label' => fn (ExpenseCategory $category) => $category->getName(),
            ])
            ->add('currency', ChoiceType::class, [
                'choices' => $this->currencies->getChoices(),
                'required' => $mainCurrency !== null,
                'placeholder' => $mainCurrency ?? 'Select a currency',
                'help' => $mainCurrency !== null
                    ? sprintf('Defaults to your main currency (%s).', $mainCurrency)
                    : null,
            ])
            ->add('convertedAmount', NumberType::class, [
                'required' => false,
                'html5' => true,
                'label' => 'Converted amount',
                'help' => $mainCurrency !== null
                    ? sprintf('Required when the currency differs from your main currency (%s): enter the converted amount directly.', $mainCurrency)
                    : null,
                'attr' => [
                    'step' => 0.01,
                    'min' => 0,
                ],
            ])
        ;

        // A new Subscription starts in the User's main currency; the User can
        // still pick another currency from the closed ISO 4217 list.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) use ($mainCurrency): void {
            $subscription = $event->getData();

            if (!$subscription instanceof Subscription) {
                return;
            }

            if ($subscription->getCurrency() === null && $mainCurrency !== null) {
                $subscription->setCurrency($mainCurrency);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Subscription::class,
            'user' => null,
            'main_currency' => null,
        ]);

        $resolver->setAllowedTypes('user', [User::class, 'null']);
        $resolver->setAllowedTypes('main_currency', ['null', 'string']);
    }
}
