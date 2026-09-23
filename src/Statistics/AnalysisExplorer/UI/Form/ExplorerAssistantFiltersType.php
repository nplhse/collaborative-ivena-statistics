<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form;

use App\Statistics\AnalysisExplorer\Application\AnalysisFilterChoiceProvider;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\ExplorerAnalysisFilterCatalog;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerAssistantDraft;
use App\Statistics\UI\Form\PreTranslatedChoiceType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Flow\ButtonFlowInterface;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<ExplorerAssistantDraft>
 */
final class ExplorerAssistantFiltersType extends AbstractType
{
    public function __construct(
        private readonly AnalysisFilterChoiceProvider $filterChoiceProvider,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $draft = $options['draft'];
        if (!$draft instanceof ExplorerAssistantDraft || AnalysisDataSourceKey::Hospitals === $draft->dataSource) {
            return;
        }

        $allowed = ExplorerAnalysisFilterCatalog::allowedExcludingAxes($draft->axisDimensionKeys());
        $booleanChoices = [
            $this->translator->trans('label.yes', [], 'messages') => 1,
            $this->translator->trans('label.no', [], 'messages') => 0,
        ];

        $this->addChoice($builder, $allowed, 'department', 'filterDepartmentId', 'label.department', 'label.all_departments', $this->flipChoices($this->filterChoiceProvider->departmentChoices()));
        $this->addChoice($builder, $allowed, 'speciality', 'filterSpecialityId', 'label.speciality', 'label.all_specialities', $this->flipChoices($this->filterChoiceProvider->specialityChoices()));
        $this->addChoice($builder, $allowed, 'urgency', 'filterUrgency', 'label.urgency', 'label.all_urgencies', $this->flipChoices($this->filterChoiceProvider->urgencyChoices()));
        $this->addChoice($builder, $allowed, 'transport_type', 'filterTransportType', 'label.transport_type', 'label.all_transport_types', $this->flipChoices($this->filterChoiceProvider->transportTypeChoices()));
        $this->addChoice($builder, $allowed, 'gender', 'filterGender', 'label.gender', 'label.all_genders', $this->flipChoices($this->filterChoiceProvider->genderChoices()));
        $this->addChoice($builder, $allowed, 'age_group', 'filterAgeGroup', 'label.age_group', 'label.all_age_groups', $this->flipChoices($this->filterChoiceProvider->ageGroupChoices()));
        $this->addChoice($builder, $allowed, 'resus', 'filterResus', 'label.requires_resus', 'label.all', $booleanChoices, true);
        $this->addChoice($builder, $allowed, 'cpr', 'filterCpr', 'label.is_cpr', 'label.all', $booleanChoices, true);
        $this->addChoice($builder, $allowed, 'ventilation', 'filterVentilation', 'label.is_ventilated', 'label.all', $booleanChoices, true);
        $this->addChoice($builder, $allowed, 'assignment', 'filterAssignmentId', 'label.assignment', 'label.all_assignments', $this->flipChoices($this->filterChoiceProvider->assignmentChoices()));
        $this->addChoice($builder, $allowed, 'indication', 'filterIndicationId', 'label.indication', 'label.all_indications', $this->flipChoices($this->filterChoiceProvider->indicationChoices()));
        $this->addChoice($builder, $allowed, 'secondary_indication', 'filterSecondaryIndicationId', 'label.secondary_indication', 'label.all_secondary_indications', $this->flipChoices($this->filterChoiceProvider->indicationChoices()));
        $this->addChoice($builder, $allowed, 'indication_group', 'filterIndicationGroupId', 'label.indication_group', 'label.all_indication_groups', $this->flipChoices($this->filterChoiceProvider->indicationGroupChoices()));

        $builder->addEventListener(FormEvents::PRE_SUBMIT, $this->restoreOnEmptySubmission(...), 100);
        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->onPostSubmit(...));
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('draft');
        $resolver->setAllowedTypes('draft', ExplorerAssistantDraft::class);
    }

    public function onPostSubmit(FormEvent $event): void
    {
        $form = $event->getForm();
        $root = $form->getRoot();
        if (!$root instanceof FormFlowInterface) {
            return;
        }

        $clicked = $root->getClickedButton();
        if ($clicked instanceof ButtonFlowInterface && !$clicked->isNextAction()) {
            return;
        }

        $draft = $form->getData();
        if (!$draft instanceof ExplorerAssistantDraft) {
            return;
        }

        $this->copySubmittedValues($form, $draft);
    }

    /**
     * Back clears the current step. Keep filters that are still visible.
     */
    public function restoreOnEmptySubmission(FormEvent $event): void
    {
        $submitted = $event->getData();
        if (\is_array($submitted) && [] !== $submitted) {
            return;
        }

        $draft = $event->getForm()->getData();
        if (!$draft instanceof ExplorerAssistantDraft) {
            return;
        }

        $restored = [];
        foreach (array_keys($this->visibleFields($draft)) as $field) {
            $value = $this->submitValue($draft, $field);
            if (null === $value) {
                continue;
            }

            $restored[$field] = $value;
        }

        $event->setData($restored);
    }

    /**
     * @param FormBuilderInterface<ExplorerAssistantDraft> $form
     * @param list<string>                                 $allowed
     * @param array<string, int|string>                    $choices
     */
    private function addChoice(
        FormBuilderInterface $form,
        array $allowed,
        string $dimension,
        string $field,
        string $label,
        string $placeholder,
        array $choices,
        bool $boolean = false,
    ): void {
        if (!\in_array($dimension, $allowed, true)) {
            return;
        }

        $form->add($field, PreTranslatedChoiceType::class, [
            'label' => $label,
            'choices' => $choices,
            'required' => false,
            'placeholder' => $placeholder,
            'translation_domain' => 'messages',
            'attr' => [
                'data-testid' => 'stats-analysis-explorer-assistant-filter-'.$dimension,
            ],
        ]);

        if (!$boolean) {
            return;
        }

        $form->get($field)->addModelTransformer(new CallbackTransformer(
            static fn (?bool $value): ?int => null === $value ? null : ($value ? 1 : 0),
            static fn (mixed $value): ?bool => null === $value || '' === $value ? null : 1 === (int) $value,
        ));
    }

    /**
     * @param array<int|string, string> $valueToLabel
     *
     * @return array<string, int|string>
     */
    private function flipChoices(array $valueToLabel): array
    {
        $choices = [];
        foreach ($valueToLabel as $value => $label) {
            $choices[$label] = $value;
        }

        return $choices;
    }

    /**
     * @param FormInterface<ExplorerAssistantDraft> $form
     */
    private function copySubmittedValues(FormInterface $form, ExplorerAssistantDraft $draft): void
    {
        $draft->filterDepartmentId = $this->nullableInt($form, 'filterDepartmentId');
        $draft->filterSpecialityId = $this->nullableInt($form, 'filterSpecialityId');
        $draft->filterUrgency = $this->nullableInt($form, 'filterUrgency');
        $draft->filterTransportType = $this->nullableInt($form, 'filterTransportType');
        $draft->filterGender = $this->nullableInt($form, 'filterGender');
        $draft->filterAgeGroup = $this->nullableString($form, 'filterAgeGroup');
        $draft->filterResus = $this->nullableBool($form, 'filterResus');
        $draft->filterCpr = $this->nullableBool($form, 'filterCpr');
        $draft->filterVentilation = $this->nullableBool($form, 'filterVentilation');
        $draft->filterAssignmentId = $this->nullableInt($form, 'filterAssignmentId');
        $draft->filterIndicationId = $this->nullableInt($form, 'filterIndicationId');
        $draft->filterSecondaryIndicationId = $this->nullableInt($form, 'filterSecondaryIndicationId');
        $draft->filterIndicationGroupId = $this->nullableInt($form, 'filterIndicationGroupId');
    }

    /**
     * @param FormInterface<ExplorerAssistantDraft> $form
     */
    private function nullableInt(FormInterface $form, string $field): ?int
    {
        if (!$form->has($field)) {
            return null;
        }

        $value = $form->get($field)->getData();
        if (null === $value || '' === $value) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param FormInterface<ExplorerAssistantDraft> $form
     */
    private function nullableString(FormInterface $form, string $field): ?string
    {
        if (!$form->has($field)) {
            return null;
        }

        $value = $form->get($field)->getData();
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }

    /**
     * @param FormInterface<ExplorerAssistantDraft> $form
     */
    private function nullableBool(FormInterface $form, string $field): ?bool
    {
        if (!$form->has($field)) {
            return null;
        }

        $value = $form->get($field)->getData();

        return \is_bool($value) ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    private function visibleFields(ExplorerAssistantDraft $draft): array
    {
        $allowed = ExplorerAnalysisFilterCatalog::allowedExcludingAxes($draft->axisDimensionKeys());
        $fields = [
            'filterDepartmentId' => 'department',
            'filterSpecialityId' => 'speciality',
            'filterUrgency' => 'urgency',
            'filterTransportType' => 'transport_type',
            'filterGender' => 'gender',
            'filterAgeGroup' => 'age_group',
            'filterResus' => 'resus',
            'filterCpr' => 'cpr',
            'filterVentilation' => 'ventilation',
            'filterAssignmentId' => 'assignment',
            'filterIndicationId' => 'indication',
            'filterSecondaryIndicationId' => 'secondary_indication',
            'filterIndicationGroupId' => 'indication_group',
        ];

        return array_filter(
            $fields,
            static fn (string $dimension): bool => \in_array($dimension, $allowed, true),
        );
    }

    private function submitValue(ExplorerAssistantDraft $draft, string $field): int|string|null
    {
        return match ($field) {
            'filterDepartmentId' => $draft->filterDepartmentId,
            'filterSpecialityId' => $draft->filterSpecialityId,
            'filterUrgency' => $draft->filterUrgency,
            'filterTransportType' => $draft->filterTransportType,
            'filterGender' => $draft->filterGender,
            'filterAgeGroup' => $draft->filterAgeGroup,
            'filterResus' => null === $draft->filterResus ? null : ($draft->filterResus ? 1 : 0),
            'filterCpr' => null === $draft->filterCpr ? null : ($draft->filterCpr ? 1 : 0),
            'filterVentilation' => null === $draft->filterVentilation ? null : ($draft->filterVentilation ? 1 : 0),
            'filterAssignmentId' => $draft->filterAssignmentId,
            'filterIndicationId' => $draft->filterIndicationId,
            'filterSecondaryIndicationId' => $draft->filterSecondaryIndicationId,
            'filterIndicationGroupId' => $draft->filterIndicationGroupId,
            default => null,
        };
    }
}
