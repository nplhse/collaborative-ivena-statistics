<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\ExplorerAnalysisFilterCatalog;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter;

final readonly class ExplorerAnalysisFilterPolicy
{
    /**
     * @param list<AnalysisFilter> $filters
     *
     * @return list<AnalysisFilter>
     */
    public function excludeAxisDimensions(array $filters, AnalysisAxisRef $rowAxis, ?AnalysisAxisRef $columnAxis): array
    {
        $axisKeys = [$rowAxis->toRegistryKey()];
        if ($columnAxis instanceof AnalysisAxisRef) {
            $axisKeys[] = $columnAxis->toRegistryKey();
        }

        return array_values(array_filter(
            $filters,
            static function (AnalysisFilter $filter) use ($axisKeys): bool {
                if (\in_array($filter->dimensionKey, $axisKeys, true)) {
                    return false;
                }

                return !('indication_group' === $filter->dimensionKey && \in_array('indication', $axisKeys, true));
            },
        ));
    }

    /**
     * @param list<AnalysisFilter> $filters
     *
     * @return list<AnalysisFilter>
     */
    public function sanitizeForConfig(AnalysisViewConfig $config, array $filters): array
    {
        $axisKeys = [$config->rowAxis->toRegistryKey()];
        if ($config->columnAxis instanceof AnalysisAxisRef) {
            $axisKeys[] = $config->columnAxis->toRegistryKey();
        }

        $allowed = ExplorerAnalysisFilterCatalog::allowedExcludingAxes($axisKeys);

        return array_values(array_filter(
            $this->excludeAxisDimensions($filters, $config->rowAxis, $config->columnAxis),
            static fn (AnalysisFilter $filter): bool => \in_array($filter->dimensionKey, $allowed, true),
        ));
    }
}
