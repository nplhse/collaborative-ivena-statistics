<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisTotals;
use App\Statistics\AnalysisExplorer\Domain\Exception\UnsupportedAnalysisException;
use App\User\Domain\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerAnalysisRunner
{
    public function __construct(
        private ExplorerConfigMapper $configMapper,
        private AnalysisViewConfigNormalizer $configNormalizer,
        private ExplorerAnalysisQueryFactory $queryFactory,
        private AnalysisRunnerRegistry $runnerRegistry,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $appliedConfigState
     */
    public function run(
        array $appliedConfigState,
        ?User $user,
        ?string $existingConfigWarning = null,
    ): ExplorerAnalysisRunOutcome {
        if ([] === $appliedConfigState) {
            return new ExplorerAnalysisRunOutcome(null, 'no_config', null);
        }

        $currentConfig = $this->configMapper->viewConfigFromState($appliedConfigState, $user);
        $originalConfig = $currentConfig;
        $normalizedConfig = $this->configNormalizer->normalize($currentConfig);
        $configWarning = $this->normalizationWarning($originalConfig, $normalizedConfig);

        $normalizedConfigState = null;
        if ([] !== $this->configNormalizer->diffWarnings($originalConfig, $normalizedConfig)) {
            $normalizedConfigState = $this->configMapper->toStateArray($normalizedConfig);
        }
        $currentConfig = $normalizedConfig;

        try {
            $query = $this->queryFactory->create($currentConfig, $user);
            $result = $this->runnerRegistry->run($currentConfig, $query);
        } catch (UnsupportedAnalysisException) {
            $configWarning ??= $this->translator->trans('stats.analysis_explorer.unsupported_config', [], 'statistics');

            return new ExplorerAnalysisRunOutcome(
                $this->emptyResult($currentConfig),
                'unsupported',
                $configWarning,
                $normalizedConfigState,
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Analysis Explorer query failed.', [
                'exception' => $exception,
            ]);

            return new ExplorerAnalysisRunOutcome(
                $this->emptyResult($currentConfig),
                'query_error',
                $this->translator->trans('stats.analysis_explorer.query_failed', [], 'statistics'),
                $normalizedConfigState,
            );
        }

        $emptyReason = [] === $result->rows ? 'no_data' : null;

        return new ExplorerAnalysisRunOutcome(
            $result,
            $emptyReason,
            $configWarning ?? $existingConfigWarning,
            $normalizedConfigState,
        );
    }

    private function normalizationWarning(AnalysisViewConfig $original, AnalysisViewConfig $normalized): ?string
    {
        if ([] === $this->configNormalizer->diffWarnings($original, $normalized)) {
            return null;
        }

        return $this->translator->trans('stats.analysis_explorer.config_normalized', [], 'statistics');
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
            totals: new AnalysisTotals(grand: []),
        );
    }
}
