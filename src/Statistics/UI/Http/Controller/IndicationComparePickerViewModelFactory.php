<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightDimensionRegistry;
use App\Statistics\Application\Insights\InsightSubject;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;

final readonly class IndicationComparePickerViewModelFactory
{
    public function __construct(
        private IndicationCompareSubjectPickerViewModelFactory $subjectPickerViewModelFactory,
        private IndicationCompareUrlHelper $compareUrlHelper,
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
        private InsightDimensionRegistry $registry,
    ) {
    }

    public function create(Request $request, InsightSubject $subjectA, ?InsightSubject $subjectB = null): IndicationComparePickerViewModel
    {
        $menuItems = $this->menuItems($subjectA);
        $selectedLabelA = $this->resolveSelectedLabel($subjectA, $menuItems);
        $selectedLabelB = $subjectB instanceof InsightSubject
            ? $this->resolveSelectedLabel($subjectB, $menuItems)
            : '';

        $compareReplace = $this->compareUrlHelper->buildCompareQueryParams(
            $subjectA,
            $subjectB ?? $subjectA,
        );
        if (!$subjectB instanceof InsightSubject) {
            unset(
                $compareReplace[StatisticsQueryKeys::SUBJECT_B_TYPE],
                $compareReplace[StatisticsQueryKeys::SUBJECT_B_ID],
            );
        }

        $compareRouteParams = ['dimension' => $subjectA->dimension->compareFamily()->value];
        $compareUrl = $this->navigationUrlBuilder->build(
            $request,
            'app_stats_insights_compare',
            array_merge($compareRouteParams, $compareReplace),
        );
        $compareBaseUrl = $this->navigationUrlBuilder->build(
            $request,
            'app_stats_insights_compare',
            $compareRouteParams,
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
            $this->subjectType($subjectA),
            $subjectB instanceof InsightSubject ? $this->subjectType($subjectB) : null,
        );
    }

    /**
     * @param list<array{type: string, id: int, label: string}> $menuItems
     */
    private function resolveSelectedLabel(InsightSubject $subject, array $menuItems): string
    {
        $type = $this->subjectType($subject);
        foreach ($menuItems as $item) {
            if ($item['type'] === $type && $item['id'] === $subject->id) {
                return $item['label'];
            }
        }

        return $subject->label;
    }

    /**
     * @return list<array{type: string, id: int, label: string}>
     */
    private function menuItems(InsightSubject $subject): array
    {
        if (InsightDimensionKey::Indications === $subject->dimension
            || InsightDimensionKey::IndicationGroups === $subject->dimension) {
            return $this->subjectPickerViewModelFactory->buildMenuItems();
        }

        $items = [];
        foreach ($this->registry->get($subject->dimension)->listEntities(null) as $entity) {
            $items[] = [
                'type' => 'single',
                'id' => $entity['id'],
                'label' => $entity['label'],
            ];
        }

        return $items;
    }

    private function subjectType(InsightSubject $subject): string
    {
        return InsightDimensionKey::IndicationGroups === $subject->dimension ? 'group' : 'single';
    }
}
