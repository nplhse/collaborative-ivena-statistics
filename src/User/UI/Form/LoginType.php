<?php

declare(strict_types=1);

namespace App\User\UI\Form;

use App\User\UI\Http\DTO\LoginTypeDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<LoginTypeDTO>
 */
final class LoginType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'label' => 'label.username',
                'translation_domain' => 'messages',
            ])
            ->add('password', PasswordType::class, [
                'label' => 'label.password',
            ])
            ->add('_remember_me', CheckboxType::class, [
                'label' => 'label.login.remember_me',
                'required' => false,
            ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LoginTypeDTO::class,
            'translation_domain' => 'user',
        ]);
    }
}
