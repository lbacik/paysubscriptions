<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'constraints' => [
                    new NotBlank(message: 'Please enter your name.'),
                    new Length(max: 255),
                ],
            ])
            ->add('email', EmailType::class, [
                'constraints' => [
                    new NotBlank(message: 'Please enter your email address.'),
                    new Email(message: 'Please enter a valid email address.'),
                    new Length(max: 180),
                ],
            ])
            ->add('subject', TextType::class, [
                'constraints' => [
                    new NotBlank(message: 'Please enter a subject.'),
                    new Length(max: 255),
                ],
            ])
            ->add('message', TextareaType::class, [
                'attr' => ['rows' => 10],
                'constraints' => [
                    new NotBlank(message: 'Please enter your message.'),
                    new Length(max: 10000),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Configure your form options here
        ]);
    }
}
