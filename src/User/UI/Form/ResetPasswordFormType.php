<?php

declare(strict_types=1);

namespace App\User\UI\Form;

use App\User\Domain\Validator\UserPasswordConstraints;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class ResetPasswordFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => UserPasswordType::class,
            'mapped' => false,
            'invalid_message' => 'validation.password.mismatch',
            'first_options' => ['label' => 'label.settings.new_password'],
            'second_options' => [
                'label' => 'label.settings.repeat_new_password',
                'strength_feedback' => false,
            ],
            'constraints' => UserPasswordConstraints::forPlainPassword(),
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'user',
            'data_class' => null,
        ]);
    }
}
