<?php

declare(strict_types=1);

namespace App\User\UI\Form;

use App\User\UI\Http\DTO\ConfirmPasswordTypeDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<ConfirmPasswordTypeDTO>
 */
final class ConfirmPasswordType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('password', PasswordType::class, [
                'label' => 'label.confirm_password',
                'constraints' => [new NotBlank()],
            ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ConfirmPasswordTypeDTO::class,
            'translation_domain' => 'user',
        ]);
    }
}
