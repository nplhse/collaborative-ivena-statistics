<?php

declare(strict_types=1);

namespace App\Allocation\UI\Form;

use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Repository\DepartmentRepository;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Allocation\Infrastructure\Repository\InfectionRepository;
use App\Allocation\Infrastructure\Repository\SecondaryTransportRepository;
use App\Allocation\Infrastructure\Repository\SpecialityRepository;
use App\Allocation\UI\Form\Model\OwnHospitalAllocationsExportFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<OwnHospitalAllocationsExportFormData>
 */
final class OwnHospitalAllocationsExportType extends AbstractType
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly IndicationNormalizedRepository $indicationNormalizedRepository,
        private readonly SecondaryTransportRepository $secondaryTransportRepository,
        private readonly InfectionRepository $infectionRepository,
        private readonly DepartmentRepository $departmentRepository,
        private readonly SpecialityRepository $specialityRepository,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dateFrom', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('dateTo', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('timeFrom', TimeType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'with_seconds' => true,
            ])
            ->add('timeTo', TimeType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'with_seconds' => true,
            ]);

        if ([] !== $options['hospital_choices']) {
            $builder->add('hospitals', ChoiceType::class, [
                'choices' => $options['hospital_choices'],
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'label' => false,
            ]);
        }

        $builder
            ->add('urgency', ChoiceType::class, [
                'choices' => $this->enumChoices(AllocationUrgency::cases()),
                'required' => false,
                'placeholder' => 'label.all_urgencies',
            ])
            ->add('transportType', ChoiceType::class, [
                'choices' => $this->enumChoices(AllocationTransportType::cases()),
                'required' => false,
                'placeholder' => 'label.all_transport_types',
            ])
            ->add('indication', ChoiceType::class, [
                'choices' => $this->indicationChoices(),
                'required' => false,
                'placeholder' => 'label.all_indications',
            ])
            ->add('secondaryTransport', ChoiceType::class, [
                'choices' => $this->entityIdChoices($this->secondaryTransportRepository->findBy([], ['name' => 'ASC'])),
                'required' => false,
                'placeholder' => 'label.all_secondary_transports',
            ])
            ->add('department', ChoiceType::class, [
                'choices' => $this->entityIdChoices($this->departmentRepository->findBy([], ['name' => 'ASC'])),
                'required' => false,
                'placeholder' => 'label.all_departments',
            ])
            ->add('speciality', ChoiceType::class, [
                'choices' => $this->entityIdChoices($this->specialityRepository->findBy([], ['name' => 'ASC'])),
                'required' => false,
                'placeholder' => 'label.all_specialities',
            ])
            ->add('requiresResus', CheckboxType::class, ['required' => false])
            ->add('requiresCathlab', CheckboxType::class, ['required' => false])
            ->add('isVentilated', CheckboxType::class, ['required' => false])
            ->add('isShock', CheckboxType::class, ['required' => false])
            ->add('isCPR', CheckboxType::class, ['required' => false])
            ->add('isPregnant', CheckboxType::class, ['required' => false])
            ->add('isWorkAccident', CheckboxType::class, ['required' => false])
            ->add('isInfectious', CheckboxType::class, ['required' => false])
            ->add('infection', ChoiceType::class, [
                'choices' => $this->entityIdChoices($this->infectionRepository->findBy([], ['name' => 'ASC'])),
                'required' => false,
                'placeholder' => 'label.all_infections',
            ]);
    }

    #[\Override]
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['hospitals_section_label'] = $options['hospitals_section_label'];
        $view->vars['hospitals_help'] = $options['hospitals_help'];
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OwnHospitalAllocationsExportFormData::class,
            'translation_domain' => 'messages',
            'hospital_choices' => [],
            'hospitals_section_label' => 'label.hospital',
            'hospitals_help' => '',
        ]);

        $resolver->setAllowedTypes('hospital_choices', 'array');
        $resolver->setAllowedTypes('hospitals_section_label', 'string');
        $resolver->setAllowedTypes('hospitals_help', 'string');
    }

    /**
     * @param list<\BackedEnum> $cases
     *
     * @return array<string, string>
     */
    private function enumChoices(array $cases): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[(string) $case->value] = (string) $case->value;
        }

        return $choices;
    }

    /**
     * @param list<object> $entities
     *
     * @return array<string, int>
     */
    private function entityIdChoices(array $entities): array
    {
        $choices = [];
        foreach ($entities as $entity) {
            if (!method_exists($entity, 'getId') || !method_exists($entity, 'getName')) {
                continue;
            }

            $id = $entity->getId();
            if (null === $id) {
                continue;
            }

            $choices[(string) $entity->getName()] = (int) $id;
        }

        return $choices;
    }

    /**
     * @return array<string, int>
     */
    private function indicationChoices(): array
    {
        $choices = [];
        foreach ($this->indicationNormalizedRepository->findAll() as $indication) {
            $code = $indication->getCode();
            if (null === $code || $code <= 0) {
                continue;
            }

            $choices[(string) $indication->getName()] = $code;
        }

        return $choices;
    }
}
