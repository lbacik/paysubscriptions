<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ExpenseCategory;
use App\Entity\Subscription;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SubscriptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User|null $user */
        $user = $options['user'];

        $builder
            ->add('name', TextType::class, [
                'attr' => [
                    'autofocus' => true,
                    'novalidate' => 'novalidate',
                ],

            ])
            ->add('firstPayment', null, [
                'widget' => 'single_text',
            ])
            ->add('category', EntityType::class, [
                'class' => ExpenseCategory::class,
                'choices' => $user?->getExpenseCategories() ?? [],
                'choice_label' => fn (ExpenseCategory $category) => $category->getName(),
            ])
            ->add('monthly', NumberType::class, [
                'required' => false,
                'html5' => true,
                'attr' => [
                    'step' => 0.01,
                    'min' => 0,
                ],
            ])
            ->add('yearly', NumberType::class, [
                'required' => false,
                'html5' => true,
                'attr' => [
                    'step' => 0.01,
                    'min' => 0,
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Subscription::class,
            'user' => null,
        ]);

        $resolver->setAllowedTypes('user', [User::class, 'null']);
    }
}
