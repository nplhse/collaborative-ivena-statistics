<?php

declare(strict_types=1);

namespace App\Encounter\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;

/**
 * New match validation sample (#1057): period (whole days, at most ReviewPeriod::MAX_DAYS) and the number
 * of items per stratum and score band.
 *
 * @extends AbstractType<array{from: ?\DateTimeImmutable, to: ?\DateTimeImmutable, sample_size: ?int}>
 */
final class ValidationSampleType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('from', DateType::class, [
                'label' => 'encounter.validation.form.from',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new NotNull()],
            ])
            ->add('to', DateType::class, [
                'label' => 'encounter.validation.form.to',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new NotNull()],
            ])
            ->add('sample_size', IntegerType::class, [
                'label' => 'encounter.validation.form.sample_size',
                'help' => 'encounter.validation.form.sample_size_help',
                'constraints' => [new NotNull(), new Range(min: 1, max: 200)],
            ])
            ->add('draw', SubmitType::class, ['label' => 'encounter.validation.form.draw']);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'encounter',
            'csrf_token_id' => 'encounter_validation_sample',
        ]);
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'validation_sample';
    }
}
