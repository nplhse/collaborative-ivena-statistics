<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class IndicationPickerViewModelFactory
{
    public function __construct(
        private IndicationNormalizedRepository $indicationRepository,
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    public function create(Request $request, ?int $currentIndicationId = null): IndicationPickerViewModel
    {
        $items = $this->indicationRepository->getDatalist();
        $selectedLabel = '';
        $menuItems = [];

        foreach ($items as $item) {
            $active = null !== $currentIndicationId && $item['id'] === $currentIndicationId;
            if ($active) {
                $selectedLabel = $item['label'];
            }

            $menuItems[] = [
                'id' => $item['id'],
                'label' => $item['label'],
                'url' => $this->navigationUrlBuilder->build(
                    $request,
                    'app_stats_indication_dashboard',
                    ['indicationId' => $item['id']],
                ),
                'active' => $active,
            ];
        }

        if ('' === $selectedLabel && null !== $currentIndicationId) {
            $fallback = $this->indicationRepository->getDatalistLabelById($currentIndicationId);
            if (null !== $fallback) {
                $selectedLabel = $fallback;
            }
        }

        return new IndicationPickerViewModel($selectedLabel, $menuItems);
    }
}
