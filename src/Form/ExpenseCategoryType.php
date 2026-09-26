<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ExpenseCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ExpenseCategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'attr' => [
                    'autofocus' => true,
                    'novalidate' => 'novalidate',
                ],
            ])
            ->add('color', ColorType::class, [
                'attr' => [
                    'class' => 'h-10 w-20 p-1',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ExpenseCategory::class,
        ]);
    }
}
