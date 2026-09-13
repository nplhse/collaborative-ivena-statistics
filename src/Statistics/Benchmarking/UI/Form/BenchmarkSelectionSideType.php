<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Form;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;
use App\Statistics\UI\Application\StatisticsFilterFormChoiceProvider;
use App\Statistics\UI\Application\StatisticsFilterScopeChoicePolicy;
use App\Statistics\UI\Application\StatisticsFilterSide;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use App\Statistics\UI\Form\PreTranslatedChoiceType;
use App\User\Domain\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * @extends AbstractType<BenchmarkSelectionSideFormData|StatisticsScopePeriodFormData>
 */
final class BenchmarkSelectionSideType extends AbstractType
{
    public function __construct(
        private readonly StatisticsFilterFormChoiceProvider $choiceProvider,
        private readonly BenchmarkSelectionSideFieldsConfigurator $fieldsConfigurator,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var string $locale */
        $locale = $options['locale'];
        /** @var StatisticsFilterScopeChoicePolicy $scopeChoicePolicy */
        $scopeChoicePolicy = $options['scope_choice_policy'];
        $user = $this->currentUser();

        $builder
            ->add('scopeGroup', PreTranslatedChoiceType::class, [
                'label' => 'stats.filter.scope_label',
                'choices' => array_flip($this->choiceProvider->scopePrimaryChoices($user, $locale, $scopeChoicePolicy)),
            ])
            ->add('period', PreTranslatedChoiceType::class, [
                'label' => 'stats.filter.period_label',
                'choices' => array_flip($this->choiceProvider->periodPrimaryChoices($locale)),
            ])
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($options): void {
            $data = $event->getData();
            if (!$data instanceof BenchmarkSelectionSideFormData && !$data instanceof StatisticsScopePeriodFormData) {
                return;
            }

            /** @var StatisticsFilterSide $side */
            $side = $options['side'];
            /** @var string $locale */
            $locale = $options['locale'];
            /** @var StatisticsFilterScopeChoicePolicy $scopeChoicePolicy */
            $scopeChoicePolicy = $options['scope_choice_policy'];
            /** @var HospitalPermission|null $hospitalPermission */
            $hospitalPermission = $options['hospital_permission'] ?? null;
            $user = $this->currentUser();

            if ($data instanceof BenchmarkSelectionSideFormData) {
                $data = $this->choiceProvider->normalizeSideFormData($data, $user, $side, $locale, $scopeChoicePolicy, $hospitalPermission);
            } else {
                $data = $this->choiceProvider->normalizeScopePeriodFormData($data, $user, $side, $locale, $scopeChoicePolicy, $hospitalPermission);
            }

            $event->setData($data);
            /** @var FormInterface<BenchmarkSelectionSideFormData|StatisticsScopePeriodFormData> $sideForm */
            $sideForm = $event->getForm();
            $this->fieldsConfigurator->configureFields($sideForm, $options, $data);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($options): void {
            $submitted = $event->getData();
            if (!\is_array($submitted)) {
                return;
            }

            if (isset($submitted['scopeDetail']) && (\is_int($submitted['scopeDetail']) || is_float($submitted['scopeDetail']))) {
                $submitted['scopeDetail'] = (string) $submitted['scopeDetail'];
            }

            $data = $event->getForm()->getData();
            $preview = $this->previewFromFormData($data, $options);

            if (isset($submitted['scopeGroup']) && \is_string($submitted['scopeGroup'])) {
                $preview->scopeGroup = $submitted['scopeGroup'];
            }
            if (isset($submitted['period']) && \is_string($submitted['period'])) {
                $preview->period = $submitted['period'];
            }
            if (isset($submitted['periodYear']) && '' !== $submitted['periodYear']) {
                $preview->periodYear = (int) $submitted['periodYear'];
            }
            if (isset($submitted['periodQuarter']) && '' !== $submitted['periodQuarter']) {
                $preview->periodQuarter = (int) $submitted['periodQuarter'];
            }
            if (isset($submitted['periodMonth']) && '' !== $submitted['periodMonth']) {
                $preview->periodMonth = (int) $submitted['periodMonth'];
            }
            if (isset($submitted['scopeDetail']) && (\is_string($submitted['scopeDetail']) || is_int($submitted['scopeDetail']))) {
                $preview->scopeDetail = (string) $submitted['scopeDetail'];
            }

            /** @var FormInterface<BenchmarkSelectionSideFormData|StatisticsScopePeriodFormData> $sideForm */
            $sideForm = $event->getForm();
            $this->fieldsConfigurator->configureFields($sideForm, $options, $preview);

            if ($sideForm->has('periodYear') && !isset($submitted['periodYear'])) {
                $submitted['periodYear'] = (string) $sideForm->get('periodYear')->getConfig()->getData();
            }
            if ($sideForm->has('periodQuarter') && !isset($submitted['periodQuarter'])) {
                $submitted['periodQuarter'] = (string) $sideForm->get('periodQuarter')->getConfig()->getData();
            }
            if ($sideForm->has('periodMonth') && !isset($submitted['periodMonth'])) {
                $submitted['periodMonth'] = (string) $sideForm->get('periodMonth')->getConfig()->getData();
            }
            if ($sideForm->has('scopeDetail') && !isset($submitted['scopeDetail'])) {
                $submitted['scopeDetail'] = (string) $sideForm->get('scopeDetail')->getConfig()->getData();
            }

            $event->setData($this->sanitizeSubmittedDynamicFields($submitted, $sideForm));
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            if (!$data instanceof BenchmarkSelectionSideFormData && !$data instanceof StatisticsScopePeriodFormData) {
                return;
            }

            $form = $event->getForm();
            if (!$form->has('scopeDetail')) {
                $data->scopeDetail = null;
            }
            if (!$form->has('periodYear')) {
                $data->periodYear = null;
            }
            if (!$form->has('periodQuarter')) {
                $data->periodQuarter = null;
            }
            if (!$form->has('periodMonth')) {
                $data->periodMonth = null;
            }
        });
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BenchmarkSelectionSideFormData::class,
            'side' => StatisticsFilterSide::Primary,
            'locale' => 'en',
            'translation_domain' => 'statistics',
            'scope_choice_policy' => StatisticsFilterScopeChoicePolicy::RegisteredHospitals,
            'hospital_permission' => null,
        ]);

        $resolver->setAllowedTypes('side', StatisticsFilterSide::class);
        $resolver->setAllowedTypes('locale', 'string');
        $resolver->setAllowedTypes('scope_choice_policy', StatisticsFilterScopeChoicePolicy::class);
        $resolver->setAllowedTypes('hospital_permission', ['null', HospitalPermission::class]);
    }

    /**
     * Drop leftover dynamic keys after a scope/period rebuild, and remap stale
     * scopeDetail onto the first valid choice (ChoiceType validates the raw submit).
     *
     * @param array<string, mixed>                                                        $submitted
     * @param FormInterface<BenchmarkSelectionSideFormData|StatisticsScopePeriodFormData> $sideForm
     *
     * @return array<string, mixed>
     */
    private function sanitizeSubmittedDynamicFields(array $submitted, FormInterface $sideForm): array
    {
        foreach (['scopeDetail', 'periodYear', 'periodQuarter', 'periodMonth'] as $fieldName) {
            if (!$sideForm->has($fieldName)) {
                unset($submitted[$fieldName]);
            }
        }

        if (!$sideForm->has('scopeDetail')) {
            return $submitted;
        }

        $field = $sideForm->get('scopeDetail');
        $submittedDetail = $submitted['scopeDetail'] ?? '';
        $submittedDetail = \is_string($submittedDetail) || \is_int($submittedDetail) || \is_float($submittedDetail)
            ? (string) $submittedDetail
            : '';

        if (!\in_array($submittedDetail, $this->choiceValues($field), true)) {
            $submitted['scopeDetail'] = (string) $field->getConfig()->getData();
        }

        return $submitted;
    }

    /**
     * @param FormInterface<mixed> $field
     *
     * @return list<string>
     */
    private function choiceValues(FormInterface $field): array
    {
        $choices = $field->getConfig()->getOption('choices');
        if (!\is_array($choices)) {
            return [];
        }

        $values = [];
        foreach ($choices as $value) {
            if (\is_string($value) || \is_int($value) || \is_float($value)) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function previewFromFormData(mixed $data, array $options): BenchmarkSelectionSideFormData|StatisticsScopePeriodFormData
    {
        if ($data instanceof BenchmarkSelectionSideFormData || $data instanceof StatisticsScopePeriodFormData) {
            return clone $data;
        }

        $dataClass = $options['data_class'] ?? BenchmarkSelectionSideFormData::class;
        if (StatisticsScopePeriodFormData::class === $dataClass) {
            return new StatisticsScopePeriodFormData();
        }

        return new BenchmarkSelectionSideFormData();
    }

    private function currentUser(): ?User
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        return $user instanceof User ? $user : null;
    }
}
