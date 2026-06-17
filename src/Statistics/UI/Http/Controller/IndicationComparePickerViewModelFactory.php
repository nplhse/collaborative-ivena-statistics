<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;

final readonly class IndicationComparePickerViewModelFactory
{
    public function __construct(
        private IndicationNormalizedRepository $indicationRepository,
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    public function create(Request $request, int $indicationIdA, ?int $indicationIdB = null): IndicationComparePickerViewModel
    {
        $items = $this->indicationRepository->getDatalist();
        $selectedLabelA = $this->indicationRepository->getDatalistLabelById($indicationIdA) ?? '';
        $selectedLabelB = null !== $indicationIdB
            ? ($this->indicationRepository->getDatalistLabelById($indicationIdB) ?? '')
            : '';

        $compareReplace = [StatisticsQueryKeys::INDICATION_A => $indicationIdA];
        if (null !== $indicationIdB) {
            $compareReplace[StatisticsQueryKeys::INDICATION_B] = $indicationIdB;
        }

        $compareUrl = $this->navigationUrlBuilder->build(
            $request,
            'app_stats_indication_compare',
            $compareReplace,
        );
        $compareBaseUrl = $this->navigationUrlBuilder->build(
            $request,
            'app_stats_indication_compare',
            [],
            [StatisticsQueryKeys::INDICATION_A, StatisticsQueryKeys::INDICATION_B],
        );

        return new IndicationComparePickerViewModel(
            $selectedLabelA,
            $selectedLabelB,
            $items,
            $compareUrl,
            $compareBaseUrl,
        );
    }
}
