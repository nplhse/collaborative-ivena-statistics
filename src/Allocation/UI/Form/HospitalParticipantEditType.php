<?php

declare(strict_types=1);

namespace App\Allocation\UI\Form;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<Hospital>
 */
final class HospitalParticipantEditType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'label.name',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(max: 255),
                ],
            ])
            ->add('dispatchArea', EntityType::class, [
                'class' => DispatchArea::class,
                'choice_label' => 'name',
                'label' => 'label.dispatch_area',
                'placeholder' => false,
                'choice_translation_domain' => false,
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('state', EntityType::class, [
                'class' => State::class,
                'choice_label' => 'name',
                'label' => 'label.state',
                'placeholder' => false,
                'choice_translation_domain' => false,
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('location', ChoiceType::class, [
                'label' => 'label.hospital_location',
                'choices' => HospitalLocation::cases(),
                'choice_label' => static fn (HospitalLocation $location): string => 'hospital.location.'.$location->value,
                'choice_value' => static fn (?HospitalLocation $location): ?string => $location?->value,
                'choice_translation_domain' => 'allocation',
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('tier', ChoiceType::class, [
                'label' => 'label.hospital_tier',
                'required' => false,
                'placeholder' => 'label.hospital.no_tier',
                'choices' => HospitalTier::cases(),
                'choice_label' => static fn (HospitalTier $tier): string => 'hospital.tier.'.$tier->value,
                'choice_value' => static fn (?HospitalTier $tier): ?string => $tier?->value,
                'choice_translation_domain' => 'allocation',
            ])
            ->add('size', ChoiceType::class, [
                'label' => 'label.hospital_size',
                'choices' => HospitalSize::cases(),
                'choice_label' => static fn (HospitalSize $size): string => 'hospital.size.'.$size->value,
                'choice_value' => static fn (?HospitalSize $size): ?string => $size?->value,
                'choice_translation_domain' => 'allocation',
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('beds', IntegerType::class, [
                'label' => 'label.hospital_beds',
                'constraints' => [
                    new Assert\NotNull(),
                    new Assert\Positive(),
                ],
            ])
            ->add('isParticipating', CheckboxType::class, [
                'label' => 'label.is_participating',
                'required' => false,
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Hospital::class,
            'translation_domain' => 'messages',
        ]);
    }
}
