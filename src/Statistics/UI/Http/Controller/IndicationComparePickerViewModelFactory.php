<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\IndicationDashboard\IndicationSubject;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;

final readonly class IndicationComparePickerViewModelFactory
{
    public function __construct(
        private IndicationCompareSubjectPickerViewModelFactory $subjectPickerViewModelFactory,
        private IndicationCompareUrlHelper $compareUrlHelper,
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    public function create(Request $request, IndicationSubject $subjectA, ?IndicationSubject $subjectB = null): IndicationComparePickerViewModel
    {
        $menuItems = $this->subjectPickerViewModelFactory->buildMenuItems();
        $selectedLabelA = $this->resolveSelectedLabel($subjectA, $menuItems);
        $selectedLabelB = $subjectB instanceof IndicationSubject
            ? $this->resolveSelectedLabel($subjectB, $menuItems)
            : '';

        $compareReplace = $this->compareUrlHelper->buildCompareQueryParams(
            $subjectA,
            $subjectB ?? $subjectA,
        );
        if (!$subjectB instanceof IndicationSubject) {
            unset(
                $compareReplace[StatisticsQueryKeys::SUBJECT_B_TYPE],
                $compareReplace[StatisticsQueryKeys::SUBJECT_B_ID],
            );
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
            [
                StatisticsQueryKeys::INDICATION_A,
                StatisticsQueryKeys::INDICATION_B,
                StatisticsQueryKeys::SUBJECT_A_TYPE,
                StatisticsQueryKeys::SUBJECT_A_ID,
                StatisticsQueryKeys::SUBJECT_B_TYPE,
                StatisticsQueryKeys::SUBJECT_B_ID,
            ],
        );

        return new IndicationComparePickerViewModel(
            $selectedLabelA,
            $selectedLabelB,
            $menuItems,
            $compareUrl,
            $compareBaseUrl,
            $subjectA->type->value,
            $subjectB?->type->value,
        );
    }

    /**
     * @param list<array{type: string, id: int, label: string}> $menuItems
     */
    private function resolveSelectedLabel(IndicationSubject $subject, array $menuItems): string
    {
        foreach ($menuItems as $item) {
            if ($item['type'] === $subject->type->value && $item['id'] === $subject->id) {
                return $item['label'];
            }
        }

        return $subject->label;
    }
}
