<?php

declare(strict_types=1);

namespace App\Encounter\UI\Form;

use App\Encounter\Domain\Enum\ValidationVerdict;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Verdict on one validation item (#1057); the choices depend on the item's stratum.
 *
 * @extends AbstractType<array{verdict: ?ValidationVerdict}>
 */
final class ValidationVerdictType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<ValidationVerdict> $verdicts */
        $verdicts = $options['verdicts'];

        $builder
            ->add('verdict', EnumType::class, [
                'class' => ValidationVerdict::class,
                'choices' => $verdicts,
                'choice_label' => static fn (ValidationVerdict $verdict): string => 'encounter.validation.verdict.'.$verdict->value,
                'expanded' => true,
                'label' => false,
                'constraints' => [new NotNull()],
            ])
            ->add('record', SubmitType::class, ['label' => 'encounter.validation.item.record']);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'encounter',
            'csrf_token_id' => 'encounter_validation_verdict',
        ]);
        $resolver->setRequired('verdicts');
        $resolver->setAllowedTypes('verdicts', 'array');
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'validation_verdict';
    }
}
