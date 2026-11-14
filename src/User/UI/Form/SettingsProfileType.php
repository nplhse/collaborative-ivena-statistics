<?php

declare(strict_types=1);

namespace App\User\UI\Form;

use App\User\Domain\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class SettingsProfileType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('displayName', TextType::class, [
            'label' => 'label.display_name',
            'help' => 'help.settings.display_name',
            'required' => false,
            'empty_data' => '',
            'attr' => [
                'autocomplete' => 'name',
                'maxlength' => User::DISPLAY_NAME_MAX_LENGTH,
            ],
            'constraints' => [
                new Length(max: User::DISPLAY_NAME_MAX_LENGTH),
            ],
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
