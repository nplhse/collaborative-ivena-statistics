<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Application\Contract\AnalysisQueryExecutorInterface;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;

final readonly class AnalysisQueryExecutorRegistry
{
    /**
     * @param iterable<AnalysisQueryExecutorInterface> $executors
     */
    public function __construct(
        private iterable $executors,
    ) {
    }

    public function get(AnalysisDataSource $dataSource): AnalysisQueryExecutorInterface
    {
        foreach ($this->executors as $executor) {
            if ($executor->supports($dataSource)) {
                return $executor;
            }
        }

        throw new \LogicException(sprintf('No analysis query executor registered for data source "%s".', $dataSource->value));
    }
}
