<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\Application\StatisticsFilterFactory;
use App\User\Domain\Entity\User;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerEditFormSummaryFactory
{
    private const string NONE_COLUMN = '';

    public function __construct(
        private TranslatorInterface $translator,
        private AllocationsCapabilitiesProvider $capabilitiesProvider,
        private ExplorerStatisticsFilterInputFactory $filterInputFactory,
        private StatisticsFilterFactory $statisticsFilterFactory,
        private AnalysisAxisResolver $axisResolver,
    ) {
    }

    /**
     * @return array{row: string, column: string, metric: string}
     */
    public function summarize(ExplorerEditFormData $formData, ?User $user): array
    {
        $filter = $this->statisticsFilterFactory->createFromInput(
            $this->filterInputFactory->fromSideFormData($formData->scopePeriod),
            $user,
        );
        $capabilities = $this->capabilitiesProvider->capabilitiesFor($user, $filter);

        $rowAxis = $this->axisResolver->resolveFromStrings(
            $formData->rowDimension,
            $formData->rowGrain,
            $capabilities,
        );

        $columnLabel = $this->translator->trans('stats.analysis_explorer.edit.structure_no_columns');
        if (null !== $formData->columnDimension && self::NONE_COLUMN !== $formData->columnDimension) {
            $columnAxis = $this->axisResolver->resolveFromStrings(
                $formData->columnDimension,
                $formData->columnGrain,
                $capabilities,
            );
            if ($capabilities->supportsColumnAxis($rowAxis, $columnAxis)) {
                $columnLabel = $this->axisLabel($columnAxis);
            }
        }

        $metricKey = AnalysisMetricKey::tryFrom($formData->metric) ?? AnalysisMetricKey::AllocationCount;

        return [
            'row' => $this->axisLabel($rowAxis),
            'column' => $columnLabel,
            'metric' => $this->translator->trans('stats.analysis_explorer.metric.'.$metricKey->value),
        ];
    }

    private function axisLabel(AnalysisAxisRef $axis): string
    {
        $dimensionLabel = $axis->dimensionKey->isTemporalPrimary()
            ? $this->translator->trans('stats.analysis_explorer.dimension.time')
            : $this->translator->trans('stats.analysis_explorer.dimension.'.$axis->dimensionKey->value);

        $grain = $axis->resolvedGrain();
        if (AnalysisDimensionKey::Time !== $axis->dimensionKey && AnalysisDimensionGrain::Total === $grain) {
            return $dimensionLabel;
        }

        return $this->translator->trans('stats.analysis_explorer.edit.structure_axis_with_grain', [
            'dimension' => $dimensionLabel,
            'grain' => $this->grainLabel($grain),
        ]);
    }

    private function grainLabel(AnalysisDimensionGrain $grain): string
    {
        return match ($grain) {
            AnalysisDimensionGrain::Month => $this->translator->trans('stats.analysis_explorer.dimension.month'),
            AnalysisDimensionGrain::Year => $this->translator->trans('stats.analysis_explorer.dimension.year'),
            AnalysisDimensionGrain::Quarter => $this->translator->trans('stats.analysis_explorer.dimension.quarter'),
            AnalysisDimensionGrain::Week => $this->translator->trans('stats.analysis_explorer.dimension.week'),
            AnalysisDimensionGrain::Total => $this->translator->trans('stats.analysis_explorer.grain.total'),
        };
    }
}
