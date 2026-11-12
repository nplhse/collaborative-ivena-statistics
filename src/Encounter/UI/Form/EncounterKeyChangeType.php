<?php

declare(strict_types=1);

namespace App\Encounter\UI\Form;

use App\Encounter\Domain\Enum\EncounterKeyChangeOption;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Confirmation of a key change (ADR 026): what happens to the existing data and the hospital name typed
 * by hand. The controller compares the name.
 *
 * @extends AbstractType<array{option: EncounterKeyChangeOption|null, confirmName: string|null}>
 */
final class EncounterKeyChangeType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('option', EnumType::class, [
                'class' => EncounterKeyChangeOption::class,
                'expanded' => true,
                'label' => 'encounter.key_change.field.option',
                'choice_label' => static fn (EncounterKeyChangeOption $option): string => 'encounter.key_change.option.'.$option->value,
                'choice_translation_domain' => 'encounter',
                'placeholder' => false,
                'constraints' => [new NotNull()],
            ])
            ->add('confirmName', TextType::class, [
                'label' => 'encounter.key_change.field.confirm_name',
                'help' => 'encounter.key_change.field.confirm_name_help',
                'help_translation_parameters' => ['name' => $options['hospital_name']],
                'attr' => ['autocomplete' => 'off'],
                'constraints' => [new NotBlank()],
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'encounter',
            'hospital_name' => '',
        ]);
        $resolver->setAllowedTypes('hospital_name', 'string');
    }
}
