<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerHospitalPopulationMode;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\Application\StatisticsFilterFactory;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class ExplorerEditAxisSwapper
{
    private const string NONE_COLUMN = '';

    public function __construct(
        private DataSourceCapabilitiesRegistry $capabilitiesRegistry,
        private AnalysisAxisResolver $axisResolver,
        private ExplorerColumnGrainResolver $columnGrainResolver,
        private ExplorerEditFormNormalizer $editFormNormalizer,
        private ExplorerStatisticsFilterInputFactory $filterInputFactory,
        private StatisticsFilterFactory $statisticsFilterFactory,
        private Security $security,
    ) {
    }

    public function canSwap(ExplorerEditFormData $formData): bool
    {
        if ($this->isHospitalCompareWithoutColumn($formData)) {
            return $this->canSwapHospitalCompareAxes($formData);
        }

        if (null === $formData->columnDimension || self::NONE_COLUMN === $formData->columnDimension) {
            return false;
        }

        $capabilities = $this->capabilitiesFor($formData);
        $rowAxis = $this->axisResolver->resolveFromStrings(
            $formData->rowDimension,
            $formData->rowGrain,
            $capabilities,
        );
        $columnAxis = $this->resolveColumnAxis($formData, $rowAxis, $capabilities);
        if (!$columnAxis instanceof AnalysisAxisRef) {
            return false;
        }

        $swappedColumnAxis = $this->axisResolver->resolveFromStrings(
            $formData->rowDimension,
            $formData->rowGrain,
            $capabilities,
        );

        return $capabilities->supportsColumnAxis($columnAxis, $swappedColumnAxis);
    }

    public function swap(ExplorerEditFormData $formData): ExplorerEditFormData
    {
        if ($this->isHospitalCompareWithoutColumn($formData)) {
            if (!$this->canSwapHospitalCompareAxes($formData)) {
                return $formData;
            }

            return $this->editFormNormalizer->normalize(new ExplorerEditFormData(
                scopePeriod: $formData->scopePeriod,
                dataSource: $formData->dataSource,
                rowDimension: AnalysisDimensionKey::HospitalPopulationGroup->value,
                rowGrain: AnalysisDimensionGrain::Total->value,
                columnDimension: $formData->rowDimension,
                columnGrain: $formData->rowGrain,
                metric: $formData->metric,
                showPercentOfTotal: $formData->showPercentOfTotal,
                chartType: $formData->chartType,
                tableLayout: $formData->tableLayout,
                chartRowLimit: $formData->chartRowLimit,
                hospitalPopulation: ExplorerHospitalPopulationMode::Compare->value,
                additionalTableMetrics: $formData->additionalTableMetrics,
            ));
        }

        if (!$this->canSwap($formData)) {
            return $formData;
        }

        return $this->editFormNormalizer->normalize(new ExplorerEditFormData(
            scopePeriod: $formData->scopePeriod,
            dataSource: $formData->dataSource,
            rowDimension: (string) $formData->columnDimension,
            rowGrain: $formData->columnGrain,
            columnDimension: $formData->rowDimension,
            columnGrain: $formData->rowGrain,
            metric: $formData->metric,
            showPercentOfTotal: $formData->showPercentOfTotal,
            chartType: $formData->chartType,
            tableLayout: $formData->tableLayout,
            chartRowLimit: $formData->chartRowLimit,
            hospitalPopulation: $formData->hospitalPopulation,
            additionalTableMetrics: $formData->additionalTableMetrics,
        ));
    }

    private function resolveColumnAxis(
        ExplorerEditFormData $formData,
        AnalysisAxisRef $rowAxis,
        DataSourceCapabilities $capabilities,
    ): ?AnalysisAxisRef {
        $columnDimensionKey = (string) $formData->columnDimension;
        $columnDimension = AnalysisDimensionKey::tryFrom($columnDimensionKey);
        if (!$columnDimension instanceof AnalysisDimensionKey) {
            return null;
        }

        $submittedColumnGrain = \is_string($formData->columnGrain)
            ? AnalysisDimensionGrain::tryFrom($formData->columnGrain)
            : null;
        $columnGrain = $this->columnGrainResolver->resolve(
            $rowAxis,
            $columnDimension,
            $submittedColumnGrain,
            $capabilities,
        );
        $candidate = $this->axisResolver->resolveFromStrings(
            $columnDimensionKey,
            $columnGrain->value,
            $capabilities,
        );

        if (!$capabilities->supportsColumnAxis($rowAxis, $candidate)) {
            return null;
        }

        return $candidate;
    }

    private function isHospitalCompareWithoutColumn(ExplorerEditFormData $formData): bool
    {
        return AnalysisDataSourceKey::Hospitals->value === $formData->dataSource
            && ExplorerHospitalPopulationMode::Compare->value === $formData->hospitalPopulation
            && (null === $formData->columnDimension || self::NONE_COLUMN === $formData->columnDimension);
    }

    private function canSwapHospitalCompareAxes(ExplorerEditFormData $formData): bool
    {
        if (AnalysisDimensionKey::HospitalPopulationGroup->value === $formData->rowDimension) {
            return false;
        }

        $capabilities = $this->capabilitiesFor($formData);
        $rowAxis = $this->axisResolver->resolveFromStrings(
            $formData->rowDimension,
            $formData->rowGrain,
            $capabilities,
        );
        $swappedRowAxis = AnalysisAxisRef::breakdown(AnalysisDimensionKey::HospitalPopulationGroup);
        $swappedColumnAxis = $this->axisResolver->resolve($rowAxis, $capabilities);

        return $capabilities->supportsColumnAxis($swappedRowAxis, $swappedColumnAxis);
    }

    private function capabilitiesFor(ExplorerEditFormData $formData): DataSourceCapabilities
    {
        $user = $this->security->getUser();
        $dataSourceKey = AnalysisDataSourceKey::tryFrom($formData->dataSource) ?? AnalysisDataSourceKey::Allocations;

        return $this->capabilitiesRegistry->capabilitiesFor(
            $dataSourceKey,
            $user instanceof User ? $user : null,
            $this->statisticsFilterFactory->createFromInput(
                $this->filterInputFactory->fromSideFormData($formData->scopePeriod),
                $user instanceof User ? $user : null,
            ),
        );
    }
}
