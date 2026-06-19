<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Application\Contract\AnalysisQueryModifierInterface;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;

final readonly class AnalysisQueryModifierRegistry
{
    /**
     * @param iterable<AnalysisQueryModifierInterface> $modifiers
     */
    public function __construct(
        private iterable $modifiers,
    ) {
    }

    public function validate(AnalysisQuery $query): void
    {
        foreach ($this->modifiersFor($query->dataSource) as $modifier) {
            $modifier->validate($query);
        }
    }

    public function prepareForExecution(AnalysisQuery $query): AnalysisQuery
    {
        $prepared = $query->withEffectiveSeriesForQuery();

        foreach ($this->modifiersFor($query->dataSource) as $modifier) {
            $prepared = $modifier->prepareForExecution($prepared);
        }

        return $prepared;
    }

    /**
     * @return list<AnalysisQueryModifierInterface>
     */
    private function modifiersFor(AnalysisDataSource $dataSource): array
    {
        $modifiers = [];
        foreach ($this->modifiers as $modifier) {
            if ($modifier->supports($dataSource)) {
                $modifiers[] = $modifier;
            }
        }

        return $modifiers;
    }
}
