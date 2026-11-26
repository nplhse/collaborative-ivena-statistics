<?php

declare(strict_types=1);

namespace App\Encounter\UI\Form;

use App\Encounter\Application\Grant\EncounterGrantInput;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\User\Domain\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<EncounterGrantInput>
 */
final class EncounterAccessGrantType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (true === $options['is_create']) {
            $builder->add('user', ChoiceType::class, [
                'label' => 'encounter.grant.field.user',
                'help' => 'encounter.grant.field.user_help',
                'choices' => $options['user_choices'],
                'choice_value' => static fn (?User $user): string => $user instanceof User ? (string) $user->getId() : '',
                'choice_label' => static fn (User $user): string => (string) $user->getUsername(),
                'choice_translation_domain' => false,
                'placeholder' => 'encounter.grant.field.user_placeholder',
            ]);
        }

        $builder->add('actions', EnumType::class, [
            'class' => EncounterGrantAction::class,
            'multiple' => true,
            'expanded' => true,
            'label' => 'encounter.grant.field.actions',
            'help' => 'encounter.grant.field.actions_help',
            'choice_label' => static fn (EncounterGrantAction $action): string => $action->labelKey(),
            'choice_translation_domain' => 'encounter',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EncounterGrantInput::class,
            'translation_domain' => 'encounter',
            'is_create' => false,
            'user_choices' => [],
            'validation_groups' => static fn (FormInterface $form): array => true === $form->getConfig()->getOption('is_create')
                ? ['Default', 'create']
                : ['Default'],
        ]);
        $resolver->setAllowedTypes('is_create', 'bool');
        $resolver->setAllowedTypes('user_choices', 'array');
    }
}
