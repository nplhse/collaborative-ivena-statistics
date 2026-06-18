<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Form;

use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;
use App\Statistics\UI\Application\StatisticsFilterFormChoiceProvider;
use App\Statistics\UI\Application\StatisticsFilterSide;
use App\User\Domain\Entity\User;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
    public function configureFields(FormInterface $form, array $options, ?BenchmarkSelectionSideFormData $data = null): void
    {
        $this->removeDynamicFields($form);

        /** @var StatisticsFilterSide $side */
        $side = $options['side'];
        /** @var string $locale */
        $locale = $options['locale'];
        $user = $this->currentUser();

        $scopeGroup = $data instanceof BenchmarkSelectionSideFormData ? $data->scopeGroup : 'public';
        $period = StatisticsFilterPeriod::tryFrom($data instanceof BenchmarkSelectionSideFormData ? $data->period : '') ?? StatisticsFilterPeriod::AllTime;
        $periodYear = $data instanceof BenchmarkSelectionSideFormData ? ($data->periodYear ?? (int) new \DateTimeImmutable()->format('Y')) : (int) new \DateTimeImmutable()->format('Y');

        if ($this->choiceProvider->scopeDetailRequired($scopeGroup, $user, $side)) {
            $detailChoices = $this->choiceProvider->scopeDetailChoices($scopeGroup, $user, $side, $locale);
            if ([] !== $detailChoices) {
                $form->add('scopeDetail', ChoiceType::class, [
                    'label' => $this->scopeDetailLabel($scopeGroup),
                    'choices' => array_flip($detailChoices),
                    'placeholder' => false,
                    'required' => false,
                    'data' => $this->defaultChoiceValue(
                        $data instanceof BenchmarkSelectionSideFormData ? $data->scopeDetail : null,
                        $detailChoices,
                    ),
                ]);
            }
        }

        if (\in_array($period, [StatisticsFilterPeriod::Year, StatisticsFilterPeriod::Quarter, StatisticsFilterPeriod::Month], true)) {
            $form->add('periodYear', ChoiceType::class, [
                'label' => 'stats.filter.period.year',
                'choices' => array_flip($this->choiceProvider->periodYearChoices()),
                'required' => false,
                'data' => (string) $periodYear,
            ]);
        }

        if (StatisticsFilterPeriod::Quarter === $period) {
            $quarter = $data instanceof BenchmarkSelectionSideFormData
                ? ($data->periodQuarter ?? 1)
                : 1;
            $form->add('periodQuarter', ChoiceType::class, [
                'label' => 'stats.filter.period.quarter',
                'choices' => array_flip($this->choiceProvider->periodQuarterChoices($periodYear, $locale)),
                'required' => false,
                'data' => (string) $quarter,
            ]);
        }

        if (StatisticsFilterPeriod::Month === $period) {
            $month = $data instanceof BenchmarkSelectionSideFormData
                ? ($data->periodMonth ?? 1)
                : 1;
            $form->add('periodMonth', ChoiceType::class, [
                'label' => 'stats.filter.period.month',
                'choices' => array_flip($this->choiceProvider->periodMonthChoices($periodYear, $locale)),
                'required' => false,
                'data' => (string) $month,
            ]);
        }
    }

    /**
     * @param array<string, string> $choices
     */
    private function defaultChoiceValue(?string $current, array $choices): ?string
    {
        if (null !== $current && '' !== $current && isset($choices[$current])) {
            return $current;
        }

        return array_key_first($choices);
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
