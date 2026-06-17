<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;

final readonly class IndicationGroupComparePickerViewModelFactory
{
    public function __construct(
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    /**
     * @param list<array{indicationId: int, label: string, total?: int}> $memberRows
     */
    public function create(Request $request, array $memberRows): IndicationComparePickerViewModel
    {
        $menuItems = array_map(
            static fn (array $row): array => [
                'id' => $row['indicationId'],
                'label' => $row['label'],
            ],
            $memberRows,
        );

        $selectedLabelA = $menuItems[0]['label'] ?? '';
        $selectedLabelB = $menuItems[1]['label'] ?? '';

        $compareReplace = [];
        if (isset($menuItems[0])) {
            $compareReplace[StatisticsQueryKeys::INDICATION_A] = $menuItems[0]['id'];
        }
        if (isset($menuItems[1])) {
            $compareReplace[StatisticsQueryKeys::INDICATION_B] = $menuItems[1]['id'];
        }

        $compareUrl = [] !== $compareReplace
            ? $this->navigationUrlBuilder->build($request, 'app_stats_indication_compare', $compareReplace)
            : $this->navigationUrlBuilder->build($request, 'app_stats_indication_compare', []);

        $compareBaseUrl = $this->navigationUrlBuilder->build(
            $request,
            'app_stats_indication_compare',
            [],
            [StatisticsQueryKeys::INDICATION_A, StatisticsQueryKeys::INDICATION_B],
        );

        return new IndicationComparePickerViewModel(
            $selectedLabelA,
            $selectedLabelB,
            array_values($menuItems),
            $compareUrl,
            $compareBaseUrl,
        );
    }

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
