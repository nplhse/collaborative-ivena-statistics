<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\Contract\ExplorerAnalysisQueryMapperInterface;
use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery as GenericAnalysisQuery;

final readonly class ExplorerQueryMapperRegistry
{
    /**
     * @param iterable<ExplorerAnalysisQueryMapperInterface> $mappers
     */
    public function __construct(
        private iterable $mappers,
    ) {
    }

    public function map(AnalysisQuery $query): GenericAnalysisQuery
    {
        foreach ($this->mappers as $mapper) {
            if ($mapper->supports($query)) {
                return $mapper->map($query);
            }
        }

        throw new \InvalidArgumentException(sprintf('No query mapper for data source "%s".', $query->dataSourceKey->value));
    }
}
