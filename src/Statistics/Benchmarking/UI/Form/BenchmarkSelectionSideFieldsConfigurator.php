<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Form;

use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;
use App\Statistics\UI\Application\StatisticsFilterFormChoiceProvider;
use App\Statistics\UI\Application\StatisticsFilterScopeChoicePolicy;
use App\Statistics\UI\Application\StatisticsFilterSide;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use App\Statistics\UI\Form\PreTranslatedChoiceType;
use App\User\Domain\Entity\User;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class BenchmarkSelectionSideFieldsConfigurator
{
    public function __construct(
        private StatisticsFilterFormChoiceProvider $choiceProvider,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    /**
     * @param FormInterface<BenchmarkSelectionSideFormData> $form
     * @param array<string, mixed>                          $options
     */
    public function configureFields(
        FormInterface $form,
        array $options,
        BenchmarkSelectionSideFormData|StatisticsScopePeriodFormData|null $data = null,
    ): void {
        $this->removeDynamicFields($form);

        /** @var StatisticsFilterSide $side */
        $side = $options['side'];
        /** @var string $locale */
        $locale = $options['locale'];
        /** @var StatisticsFilterScopeChoicePolicy $scopeChoicePolicy */
        $scopeChoicePolicy = $options['scope_choice_policy'];
        $user = $this->currentUser();

        $scopeGroup = 'public';
        $period = StatisticsFilterPeriod::AllTime;
        $periodYear = (int) new \DateTimeImmutable()->format('Y');
        $scopeDetail = null;
        $periodQuarter = 1;
        $periodMonth = 1;

        if (null !== $data) {
            $scopeGroup = $data->scopeGroup;
            $period = StatisticsFilterPeriod::tryFrom($data->period) ?? StatisticsFilterPeriod::AllTime;
            $periodYear = $data->periodYear ?? $periodYear;
            $scopeDetail = $data->scopeDetail;
            $periodQuarter = $data->periodQuarter ?? $periodQuarter;
            $periodMonth = $data->periodMonth ?? $periodMonth;
        }

        if ($this->choiceProvider->scopeDetailRequired($scopeGroup, $user, $side, $scopeChoicePolicy)) {
            $detailChoices = $this->choiceProvider->scopeDetailChoices($scopeGroup, $user, $side, $locale, $scopeChoicePolicy);
            if ([] !== $detailChoices) {
                $form->add('scopeDetail', PreTranslatedChoiceType::class, [
                    'label' => $this->scopeDetailLabel($scopeGroup),
                    'choices' => $this->flippedStringValueChoices($detailChoices),
                    'choice_value' => static fn (?string $choice): string => $choice ?? '',
                    'placeholder' => false,
                    'required' => false,
                    'data' => $this->defaultChoiceValue($scopeDetail, $detailChoices),
                ]);
            }
        }

        if (\in_array($period, [StatisticsFilterPeriod::Year, StatisticsFilterPeriod::Quarter, StatisticsFilterPeriod::Month], true)) {
            $form->add('periodYear', PreTranslatedChoiceType::class, [
                'label' => 'stats.filter.period.year',
                'choices' => array_flip($this->choiceProvider->periodYearChoices()),
                'required' => false,
                'data' => (string) $periodYear,
            ]);
        }

        if (StatisticsFilterPeriod::Quarter === $period) {
            $form->add('periodQuarter', PreTranslatedChoiceType::class, [
                'label' => 'stats.filter.period.quarter',
                'choices' => array_flip($this->choiceProvider->periodQuarterChoices($periodYear, $locale)),
                'required' => false,
                'data' => (string) $periodQuarter,
            ]);
        }

        if (StatisticsFilterPeriod::Month === $period) {
            $form->add('periodMonth', PreTranslatedChoiceType::class, [
                'label' => 'stats.filter.period.month',
                'choices' => array_flip($this->choiceProvider->periodMonthChoices($periodYear, $locale)),
                'required' => false,
                'data' => (string) $periodMonth,
            ]);
        }
    }

    /**
     * @param array<int|string, string> $choices
     */
    private function defaultChoiceValue(?string $current, array $choices): ?string
    {
        if (null !== $current && '' !== $current && isset($choices[$current])) {
            return $current;
        }

        $first = array_key_first($choices);
        if (null === $first) {
            return null;
        }

        return (string) $first;
    }

    /**
     * @param array<int|string, string> $choices
     *
     * @return array<string, string>
     */
    private function flippedStringValueChoices(array $choices): array
    {
        $flipped = [];
        foreach ($choices as $id => $label) {
            $flipped[$label] = (string) $id;
        }

        return $flipped;
    }

    /**
     * @param FormInterface<BenchmarkSelectionSideFormData> $form
     */
    private function removeDynamicFields(FormInterface $form): void
    {
        foreach (['scopeDetail', 'periodYear', 'periodQuarter', 'periodMonth'] as $fieldName) {
            if ($form->has($fieldName)) {
                $form->remove($fieldName);
            }
        }
    }

    private function scopeDetailLabel(string $scopeGroup): string
    {
        return match ($scopeGroup) {
            'state' => 'stats.filter.scope.state',
            'dispatch_area' => 'stats.filter.scope.dispatch_area',
            'hospital_cohort' => 'stats.filter.scope.hospital_cohort',
            'my_hospitals' => 'stats.filter.hospital.choose',
            default => 'stats.filter.scope_label',
        };
    }

    private function currentUser(): ?User
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        return $user instanceof User ? $user : null;
    }
}
