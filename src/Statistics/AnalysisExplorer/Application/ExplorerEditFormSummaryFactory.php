<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
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
        private DataSourceCapabilitiesRegistry $capabilitiesRegistry,
        private ExplorerStatisticsFilterInputFactory $filterInputFactory,
        private StatisticsFilterFactory $statisticsFilterFactory,
        private AnalysisAxisResolver $axisResolver,
        private ExplorerColumnGrainResolver $columnGrainResolver,
        private ExplorerMetricProfileRegistry $metricProfileRegistry,
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
        $dataSourceKey = AnalysisDataSourceKey::tryFrom($formData->dataSource) ?? AnalysisDataSourceKey::Allocations;
        $capabilities = $this->capabilitiesRegistry->capabilitiesFor($dataSourceKey, $user, $filter);

        $rowAxis = $this->axisResolver->resolveFromStrings(
            $formData->rowDimension,
            $formData->rowGrain,
            $capabilities,
        );

        $columnLabel = $this->translator->trans('stats.analysis_explorer.edit.structure_no_columns', [], 'statistics');
        if (null !== $formData->columnDimension && self::NONE_COLUMN !== $formData->columnDimension) {
            $columnDimension = AnalysisDimensionKey::tryFrom($formData->columnDimension);
            if ($columnDimension instanceof AnalysisDimensionKey) {
                $submittedColumnGrain = \is_string($formData->columnGrain)
                    ? AnalysisDimensionGrain::tryFrom($formData->columnGrain)
                    : null;
                $columnGrain = $this->columnGrainResolver->resolve(
                    $rowAxis,
                    $columnDimension,
                    $submittedColumnGrain,
                    $capabilities,
                );
                $columnAxis = $this->axisResolver->resolveFromStrings(
                    $formData->columnDimension,
                    $columnGrain->value,
                    $capabilities,
                );
                if ($capabilities->supportsColumnAxis($rowAxis, $columnAxis)) {
                    $columnLabel = $this->axisLabel($columnAxis);
                }
            }
        }

        $metricKey = AnalysisMetricKey::tryFrom($formData->metric) ?? AnalysisMetricKey::defaultFor($dataSourceKey);

        return [
            'row' => $this->axisLabel($rowAxis),
            'column' => $columnLabel,
            'metric' => $this->metricLabel($metricKey),
        ];
    }

    private function metricLabel(AnalysisMetricKey $metricKey): string
    {
        $profile = $this->metricProfileRegistry->profileFor($metricKey);
        if ($profile instanceof \App\Statistics\AnalysisExplorer\Domain\DTO\ExplorerMetricProfileDefinition) {
            return $this->translator->trans($profile->labelTranslationKey, [], 'statistics');
        }

        return $this->translator->trans('stats.analysis_explorer.metric.'.$metricKey->value, [], 'statistics');
    }

    private function axisLabel(AnalysisAxisRef $axis): string
    {
        $dimensionLabel = $axis->dimensionKey->isTemporalPrimary()
            ? $this->translator->trans('stats.analysis_explorer.dimension.time', [], 'statistics')
            : $this->translator->trans('stats.analysis_explorer.dimension.'.$axis->dimensionKey->value, [], 'statistics');

        $grain = $axis->resolvedGrain();
        if (AnalysisDimensionKey::Time !== $axis->dimensionKey && AnalysisDimensionGrain::Total === $grain) {
            return $dimensionLabel;
        }

        return $this->translator->trans('stats.analysis_explorer.edit.structure_axis_with_grain', [
            'dimension' => $dimensionLabel,
            'grain' => $this->grainLabel($grain),
        ], 'statistics');
    }

    private function grainLabel(AnalysisDimensionGrain $grain): string
    {
        return match ($grain) {
            AnalysisDimensionGrain::Month => $this->translator->trans('stats.analysis_explorer.dimension.month', [], 'statistics'),
            AnalysisDimensionGrain::Year => $this->translator->trans('stats.analysis_explorer.dimension.year', [], 'statistics'),
            AnalysisDimensionGrain::Quarter => $this->translator->trans('stats.analysis_explorer.dimension.quarter', [], 'statistics'),
            AnalysisDimensionGrain::Week => $this->translator->trans('stats.analysis_explorer.dimension.week', [], 'statistics'),
            AnalysisDimensionGrain::Total => $this->translator->trans('stats.analysis_explorer.grain.total', [], 'statistics'),
        };
    }
}
