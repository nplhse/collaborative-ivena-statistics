<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

final readonly class IndicationGroupComparePickerViewModelFactory
{
    /**
     * @param list<array{indicationId: int, label: string, total?: int}> $memberRows
     *
     * @return list<array{label: string, labelA: string, labelB: string}>
     */
    public function createPresets(array $memberRows): array
    {
        if (\count($memberRows) < 2) {
            return [];
        }

        $presets = [
            [
                'label' => 'stats.indication.group.compare_preset_top_two',
                'labelA' => $memberRows[0]['label'],
                'labelB' => $memberRows[1]['label'],
            ],
        ];

        if (\count($memberRows) >= 3) {
            $lastIndex = \count($memberRows) - 1;
            $presets[] = [
                'label' => 'stats.indication.group.compare_preset_largest_smallest',
                'labelA' => $memberRows[0]['label'],
                'labelB' => $memberRows[$lastIndex]['label'],
            ];
        }

        return $presets;
    }
}
