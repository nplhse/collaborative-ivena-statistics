<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Allocation\Infrastructure\Repository\IndicationGroupRepository;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class IndicationGroupPickerViewModelFactory
{
    public function __construct(
        private IndicationGroupRepository $groupRepository,
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    public function create(Request $request, ?int $currentGroupId = null): IndicationGroupPickerViewModel
    {
        $items = $this->groupRepository->getDatalist();
        $selectedLabel = '';
        $menuItems = [];

        foreach ($items as $item) {
            $itemId = $item['id'];
            $active = null !== $currentGroupId && $itemId === $currentGroupId;
            if ($active) {
                $selectedLabel = $item['label'];
            }

            $menuItems[] = [
                'id' => $itemId,
                'label' => $item['label'],
                'url' => $this->navigationUrlBuilder->build(
                    $request,
                    'app_stats_indication_group_dashboard',
                    ['groupId' => $item['id']],
                ),
                'active' => $active,
            ];
        }

        return new IndicationGroupPickerViewModel($selectedLabel, $menuItems);
    }
}
