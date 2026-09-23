<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\DTO\AnalysisExecutionResult;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisTotals;
use App\Statistics\AnalysisExplorer\Domain\Exception\InvalidExplorerConfigException;
use App\Statistics\AnalysisExplorer\Domain\Exception\UnsupportedAnalysisException;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsSourceDataProbe;
use App\User\Domain\Entity\User;
use Psr\Log\LoggerInterface;

final readonly class AnalysisExecution
{
    public function __construct(
        private AnalysisViewConfigNormalizer $configNormalizer,
        private AnalysisViewConfigValidator $configValidator,
        private ExplorerAnalysisQueryFactory $queryFactory,
        private AnalysisRunnerRegistry $runnerRegistry,
        private ExplorerChartPresenter $chartPresenter,
        private ExplorerResultsTablePresenter $tablePresenter,
        private HospitalAccessInterface $hospitalAccess,
        private StatisticsSourceDataProbe $sourceDataProbe,
        private LoggerInterface $logger,
    ) {
    }

    public function execute(AnalysisViewConfig $config, ?User $user): AnalysisExecutionResult
    {
        $normalized = $this->configNormalizer->normalize($config);
        $rewritten = [] !== $this->configNormalizer->diffWarnings($config, $normalized);

        try {
            $this->configValidator->validate($normalized);
        } catch (InvalidExplorerConfigException $exception) {
            return $this->failed($normalized, $rewritten, 'unsupported', $exception->translationKey, $exception->parameters);
        }

        if ($this->hospitalScopeDenied($normalized, $user)) {
            return $this->failed($normalized, $rewritten, 'scope_forbidden');
        }

        try {
            $query = $this->queryFactory->create($normalized, $user);
            $result = $this->runnerRegistry->run($normalized, $query);
        } catch (UnsupportedAnalysisException) {
            return $this->failed($normalized, $rewritten, 'unsupported', 'stats.analysis_explorer.unsupported_config');
        } catch (\Throwable $exception) {
            $this->logger->error('Analysis explorer query failed.', [
                'exception' => $exception,
                'dataSource' => $normalized->dataSourceKey->value,
                'rowDimension' => $normalized->rowAxis->dimensionKey->value,
                'columnDimension' => $normalized->columnAxis?->dimensionKey->value,
                'metric' => $normalized->visualMetricKey->value,
            ]);

            return $this->failed($normalized, $rewritten, 'query_error', 'stats.analysis_explorer.query_failed');
        }

        $emptyReason = null;
        if ([] === $result->rows) {
            if ([] !== $normalized->filters) {
                $emptyReason = 'filtered';
            } elseif (!$this->sourceDataProbe->hasSourceData($user, $normalized->statisticsFilter)) {
                $emptyReason = 'no_source';
            } else {
                $emptyReason = 'no_data';
            }
        }

        return new AnalysisExecutionResult(
            config: $normalized,
            result: $result,
            chartSpecs: $this->chartPresenter->buildSpecs($result, $normalized->presentation),
            defaultChartType: $this->chartPresenter->defaultChartType($normalized->presentation),
            hasChart: $this->chartPresenter->hasChart($result),
            table: $this->tablePresenter->create($normalized, $result),
            emptyReason: $emptyReason,
            configRewritten: $rewritten,
        );
    }

    /**
     * @param array<string, string|int|float> $warningParameters
     */
    private function failed(
        AnalysisViewConfig $config,
        bool $rewritten,
        string $emptyReason,
        ?string $warningKey = null,
        array $warningParameters = [],
    ): AnalysisExecutionResult {
        $empty = $this->emptyResult($config);

        return new AnalysisExecutionResult(
            config: $config,
            result: $empty,
            chartSpecs: [],
            defaultChartType: $config->presentation->chartType->value,
            hasChart: false,
            table: $this->tablePresenter->create($config, $empty),
            emptyReason: $emptyReason,
            configRewritten: $rewritten,
            warningKey: $warningKey,
            warningParameters: $warningParameters,
        );
    }

    private function emptyResult(AnalysisViewConfig $config): AnalysisRunResult
    {
        return new AnalysisRunResult(
            title: $config->title,
            metricKeys: $config->metricKeys,
            visualMetricKey: $config->visualMetricKey,
            rowAxis: $config->rowAxis,
            columnAxis: $config->columnAxis,
            rows: [],
            totals: new AnalysisTotals([]),
        );
    }

    private function hospitalScopeDenied(AnalysisViewConfig $config, ?User $user): bool
    {
        $filter = $config->statisticsFilter;
        if (StatisticsFilterScope::Hospital !== $filter->scope) {
            return false;
        }

        $hospitalId = $filter->hospitalId;
        if (!is_int($hospitalId)) {
            return true;
        }

        if (!$user instanceof User) {
            return true;
        }

        return !$this->hospitalAccess->canSelectHospitalScope($user, $hospitalId);
    }
}
