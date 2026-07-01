<?php

declare(strict_types=1);

namespace App\Allocation\UI\Form;

use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Repository\AssignmentRepository;
use App\Allocation\Infrastructure\Repository\DepartmentRepository;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Allocation\Infrastructure\Repository\InfectionRepository;
use App\Allocation\Infrastructure\Repository\OccasionRepository;
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
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly AssignmentRepository $assignmentRepository,
        private readonly OccasionRepository $occasionRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dateFrom', DateType::class, [
                'label' => 'field.dateFrom',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('dateTo', DateType::class, [
                'label' => 'field.dateTo',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('timeFrom', TimeType::class, [
                'label' => 'field.timeFrom',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'with_seconds' => true,
            ])
            ->add('timeTo', TimeType::class, [
                'label' => 'field.timeTo',
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
                'choice_translation_domain' => false,
            ]);
        }

        $builder
            ->add('urgency', ChoiceType::class, $this->choiceFieldOptions([
                'label' => 'field.urgency',
                'choices' => $this->urgencyChoices(),
                'placeholder' => 'label.all_urgencies',
            ]))
            ->add('assignment', ChoiceType::class, $this->choiceFieldOptions([
                'label' => 'label.assignment',
                'choices' => $this->entityIdChoices($this->assignmentRepository->findBy([], ['name' => 'ASC'])),
                'placeholder' => 'label.all_assignments',
            ]))
            ->add('occasion', ChoiceType::class, $this->choiceFieldOptions([
                'label' => 'label.occasion',
                'choices' => $this->entityIdChoices($this->occasionRepository->findBy([], ['name' => 'ASC'])),
                'placeholder' => 'label.all_occasions',
            ]))
            ->add('transportType', ChoiceType::class, $this->choiceFieldOptions([
                'label' => 'field.transportType',
                'choices' => $this->transportTypeChoices(),
                'placeholder' => 'label.all_transport_types',
            ]))
            ->add('indication', ChoiceType::class, $this->choiceFieldOptions([
                'label' => 'label.indication',
                'choices' => $this->indicationChoices(),
                'placeholder' => 'label.all_indications',
            ]))
            ->add('includeIndicationRaw', CheckboxType::class, [
                'required' => false,
                'label' => new TranslatableMessage('field.includeIndicationRaw', domain: 'messages'),
                'help' => new TranslatableMessage('help.export.include_indication_raw', domain: 'allocation'),
            ])
            ->add('secondaryTransport', ChoiceType::class, $this->choiceFieldOptions([
                'label' => 'label.secondary_transport',
                'choices' => $this->entityIdChoices($this->secondaryTransportRepository->findBy([], ['name' => 'ASC'])),
                'placeholder' => 'label.all_secondary_transports',
            ]))
            ->add('department', ChoiceType::class, $this->choiceFieldOptions([
                'label' => 'label.department',
                'choices' => $this->entityIdChoices($this->departmentRepository->findBy([], ['name' => 'ASC'])),
                'placeholder' => 'label.all_departments',
            ]))
            ->add('speciality', ChoiceType::class, $this->choiceFieldOptions([
                'label' => 'label.speciality',
                'choices' => $this->entityIdChoices($this->specialityRepository->findBy([], ['name' => 'ASC'])),
                'placeholder' => 'label.all_specialities',
            ]))
            ->add('departmentWasClosed', CheckboxType::class, [
                'required' => false,
                'label' => new TranslatableMessage('field.departmentWasClosed', domain: 'messages'),
                'help' => new TranslatableMessage('help.export.department_was_closed_filter', domain: 'allocation'),
            ])
            ->add('requiresResus', CheckboxType::class, [
                'label' => 'field.requiresResus',
                'required' => false,
            ])
            ->add('requiresCathlab', CheckboxType::class, [
                'label' => 'field.requiresCathlab',
                'required' => false,
            ])
            ->add('isVentilated', CheckboxType::class, [
                'label' => new TranslatableMessage('allocations.field.isVentilated', domain: 'allocation'),
                'required' => false,
            ])
            ->add('isShock', CheckboxType::class, [
                'label' => new TranslatableMessage('allocations.field.isShock', domain: 'allocation'),
                'required' => false,
            ])
            ->add('isCPR', CheckboxType::class, [
                'label' => 'field.isCPR',
                'required' => false,
            ])
            ->add('isPregnant', CheckboxType::class, [
                'label' => new TranslatableMessage('allocations.field.isPregnant', domain: 'allocation'),
                'required' => false,
            ])
            ->add('isWorkAccident', CheckboxType::class, [
                'label' => new TranslatableMessage('allocations.field.isWorkAccident', domain: 'allocation'),
                'required' => false,
            ])
            ->add('isInfectious', CheckboxType::class, [
                'label' => 'label.is_infectious',
                'required' => false,
            ])
            ->add('infection', ChoiceType::class, $this->choiceFieldOptions([
                'label' => 'field.infection',
                'choices' => $this->entityIdChoices($this->infectionRepository->findBy([], ['name' => 'ASC'])),
                'placeholder' => 'label.all_infections',
            ]));
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
     * Entity names and SK labels are domain terms and must not be passed through the translator.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function choiceFieldOptions(array $options): array
    {
        return array_merge([
            'choice_translation_domain' => false,
            'required' => false,
        ], $options);
    }

    /**
     * @return array<string, string>
     */
    private function urgencyChoices(): array
    {
        $choices = [];
        foreach (AllocationUrgency::cases() as $case) {
            $choices[$case->skLabel()] = (string) $case->value;
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    private function transportTypeChoices(): array
    {
        $choices = [];
        foreach (AllocationTransportType::cases() as $case) {
            $choices[$this->translator->trans($case->label(), [], 'messages')] = $case->value;
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
}
