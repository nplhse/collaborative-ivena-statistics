<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerFilterBadgePresenter
{
    public function __construct(
        private DimensionRegistry $dimensionRegistry,
        private AnalysisFilterChoiceProvider $choiceProvider,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public function present(AnalysisViewConfig $config): array
    {
        $badges = [];
        foreach ($config->filters as $filter) {
            $badges[] = [
                'label' => $this->dimensionLabel($filter->dimensionKey),
                'value' => $this->valueLabel($filter),
            ];
        }

        return $badges;
    }

    private function dimensionLabel(string $dimensionKey): string
    {
        if ('indication_group' === $dimensionKey) {
            return $this->translator->trans('label.indication_group', [], 'messages');
        }

        if (!$this->dimensionRegistry->has($dimensionKey)) {
            return $dimensionKey;
        }

        return $this->dimensionRegistry->get($dimensionKey)->label;
    }

    private function valueLabel(AnalysisFilter $filter): string
    {
        return match ($filter->dimensionKey) {
            'department' => $this->singleChoiceLabel($this->choiceProvider->departmentChoices(), $filter->value),
            'speciality' => $this->singleChoiceLabel($this->choiceProvider->specialityChoices(), $filter->value),
            'assignment' => $this->entityListLabel($this->choiceProvider->assignmentChoices(), $filter->value),
            'urgency' => $this->singleChoiceLabel($this->choiceProvider->urgencyChoices(), $filter->value),
            'transport_type' => $this->singleChoiceLabel($this->choiceProvider->transportTypeChoices(), $filter->value),
            'gender' => $this->singleChoiceLabel($this->choiceProvider->genderChoices(), $filter->value),
            'age_group' => $this->singleChoiceLabel($this->choiceProvider->ageGroupChoices(), $filter->value),
            'indication' => $this->singleChoiceLabel($this->choiceProvider->indicationChoices(), $filter->value),
            'secondary_indication' => $this->singleChoiceLabel($this->choiceProvider->indicationChoices(), $filter->value),
            'indication_group' => $this->singleChoiceLabel($this->choiceProvider->indicationGroupChoices(), $filter->value),
            'resus', 'cpr', 'ventilation' => $this->booleanLabel($filter->value),
            default => \is_array($filter->value) ? implode(', ', array_map(strval(...), $filter->value)) : (string) $filter->value,
        };
    }

    /**
     * @param array<int|string, string>             $choices
     * @param int|string|bool|list<int|string|bool> $value
     */
    private function entityListLabel(array $choices, int|string|bool|array $value): string
    {
        $values = \is_array($value) ? $value : [$value];
        $labels = [];
        foreach ($values as $item) {
            $key = \is_int($item) ? $item : (int) $item;
            $labels[] = $choices[$key] ?? (string) $item;
        }

        return implode(', ', $labels);
    }

    /**
     * @param array<int|string, string>             $choices
     * @param int|string|bool|list<int|string|bool> $value
     */
    private function singleChoiceLabel(array $choices, int|string|bool|array $value): string
    {
        if (\is_array($value)) {
            return $this->entityListLabel($choices, $value);
        }

        $key = \is_int($value) ? $value : (is_numeric((string) $value) ? (int) $value : (string) $value);

        return $choices[$key] ?? (string) $value;
    }

    /**
     * @param int|string|bool|list<int|string|bool> $value
     */
    private function booleanLabel(int|string|bool|array $value): string
    {
        if (\is_array($value)) {
            $value = $value[0] ?? 0;
        }

        $normalized = \is_bool($value) ? ($value ? 1 : 0) : (int) $value;

        return 1 === $normalized
            ? $this->translator->trans('label.yes', [], 'messages')
            : $this->translator->trans('label.no', [], 'messages');
    }
}
