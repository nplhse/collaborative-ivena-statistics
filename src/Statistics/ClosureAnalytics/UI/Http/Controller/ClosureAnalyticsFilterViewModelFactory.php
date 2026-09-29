<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\UI\Http\Controller;

use App\Allocation\Domain\Enum\ClosureCareLevel;
use App\Allocation\Domain\Enum\ClosureReason;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsFilter;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureTemporalQuery;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ClosureAnalyticsFilterViewModelFactory
{
    public function __construct(
        private ClosureTemporalQuery $temporalQuery,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array{
     *   activeCount: int,
     *   filterKeys: list<string>,
     *   showClosureUnits: bool,
     *   values: array{departments: list<int>, specialities: list<int>, careLevels: list<string>, reasons: list<string>, closureUnits: list<string>},
     *   choices: array{
     *     departments: list<array{id: int, name: string}>,
     *     specialities: list<array{id: int, name: string}>,
     *     careLevels: list<array{value: string, name: string}>,
     *     reasons: list<array{value: string, name: string}>,
     *     closureUnits: list<array{value: string, name: string}>
     *   },
     *   badges: list<array{label: string, value: string}>
     * }
     */
    public function create(ClosureAnalyticsCriteria $baseCriteria, ClosureAnalyticsFilter $filter): array
    {
        $departments = $this->temporalQuery->fetchDepartmentChoices($baseCriteria);
        $specialities = $this->temporalQuery->fetchSpecialityChoices($baseCriteria);
        $showClosureUnits = \in_array(
            $baseCriteria->filter->scope,
            [StatisticsFilterScope::Hospital, StatisticsFilterScope::MyHospitals],
            true,
        );
        $closureUnits = $showClosureUnits
            ? $this->temporalQuery->fetchClosureUnitChoices($baseCriteria)
            : [];
        $careLevels = array_map(
            fn (ClosureCareLevel $careLevel): array => [
                'value' => $careLevel->value,
                'name' => $this->careLevelLabel($careLevel),
            ],
            ClosureCareLevel::cases(),
        );
        $reasons = array_map(
            fn (ClosureReason $reason): array => [
                'value' => $reason->value,
                'name' => $this->reasonLabel($reason),
            ],
            ClosureReason::cases(),
        );

        $badges = [];
        $this->addBadge(
            $badges,
            'label.department',
            $this->selectedNames($departments, $filter->departmentIds),
        );
        $this->addBadge(
            $badges,
            'label.speciality',
            $this->selectedNames($specialities, $filter->specialityIds),
        );
        $this->addBadge(
            $badges,
            'label.urgency',
            array_map(
                fn (string $value): string => $this->careLevelLabel(ClosureCareLevel::from($value)),
                $filter->careLevels,
            ),
        );
        $this->addBadge(
            $badges,
            'stats.closure.filter.reasons',
            array_map(
                fn (string $value): string => $this->reasonLabel(ClosureReason::from($value)),
                $filter->reasons,
            ),
            'statistics',
        );
        if ($showClosureUnits) {
            $this->addBadge(
                $badges,
                'stats.closure.filter.closure_units',
                $this->selectedStringNames($closureUnits, $filter->closureUnits),
                'statistics',
            );
        }

        return [
            'activeCount' => \count($badges),
            'filterKeys' => ClosureAnalyticsFilterRequestResolver::QUERY_KEYS,
            'showClosureUnits' => $showClosureUnits,
            'values' => [
                'departments' => $filter->departmentIds,
                'specialities' => $filter->specialityIds,
                'careLevels' => $filter->careLevels,
                'reasons' => $filter->reasons,
                'closureUnits' => $showClosureUnits ? $filter->closureUnits : [],
            ],
            'choices' => [
                'departments' => $departments,
                'specialities' => $specialities,
                'careLevels' => $careLevels,
                'reasons' => $reasons,
                'closureUnits' => $closureUnits,
            ],
            'badges' => $badges,
        ];
    }

    private function careLevelLabel(ClosureCareLevel $careLevel): string
    {
        $urgency = $careLevel->toAllocationUrgency();
        if (!$urgency instanceof \App\Allocation\Domain\Enum\AllocationUrgency) {
            return $this->translator->trans('stats.closure.care_level.other', domain: 'statistics');
        }

        return sprintf(
            '%s · %s',
            $urgency->skLabel(),
            $this->translator->trans($urgency->label(), domain: 'messages'),
        );
    }

    private function reasonLabel(ClosureReason $reason): string
    {
        return $this->translator->trans('stats.closure.reason.'.$reason->value, domain: 'statistics');
    }

    /**
     * @param list<array{id: int, name: string}> $choices
     * @param list<int>                          $selectedIds
     *
     * @return list<string>
     */
    private function selectedNames(array $choices, array $selectedIds): array
    {
        $namesById = [];
        foreach ($choices as $choice) {
            $namesById[$choice['id']] = $choice['name'];
        }

        $names = [];
        foreach ($selectedIds as $selectedId) {
            $names[] = $namesById[$selectedId] ?? '#'.$selectedId;
        }

        return $names;
    }

    /**
     * @param list<array{value: string, name: string}> $choices
     * @param list<string>                             $selectedValues
     *
     * @return list<string>
     */
    private function selectedStringNames(array $choices, array $selectedValues): array
    {
        $namesByValue = [];
        foreach ($choices as $choice) {
            $namesByValue[$choice['value']] = $choice['name'];
        }

        return array_map(
            static fn (string $value): string => $namesByValue[$value] ?? $value,
            $selectedValues,
        );
    }

    /**
     * @param list<array{label: string, value: string}> $badges
     * @param list<string>                              $values
     */
    private function addBadge(
        array &$badges,
        string $labelKey,
        array $values,
        string $domain = 'messages',
    ): void {
        if ([] === $values) {
            return;
        }

        $badges[] = [
            'label' => $this->translator->trans($labelKey, domain: $domain),
            'value' => implode(', ', $values),
        ];
    }
}
