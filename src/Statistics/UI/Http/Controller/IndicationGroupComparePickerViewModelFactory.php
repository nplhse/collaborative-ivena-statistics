<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

final readonly class IndicationGroupComparePickerViewModelFactory
{
    /**
     * Group-detail presets choose side B only; A stays the current group.
     *
     * @param list<array{indicationId: int, label: string, total?: int}> $memberRows
     *
     * @return list<array{label: string, labelB: string, indicationId: int}>
     */
    public function createPresets(array $memberRows): array
    {
        if ([] === $memberRows) {
            return [];
        }

        $presets = [
            [
                'label' => 'stats.indication.group.compare_preset_top_two',
                'labelB' => $memberRows[0]['label'],
                'indicationId' => $memberRows[0]['indicationId'],
            ],
        ];

        $lastIndex = \count($memberRows) - 1;
        if ($lastIndex > 0) {
            $presets[] = [
                'label' => 'stats.indication.group.compare_preset_largest_smallest',
                'labelB' => $memberRows[$lastIndex]['label'],
                'indicationId' => $memberRows[$lastIndex]['indicationId'],
            ];
        }

        return $presets;
    }
}
