<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Analysis\AnalysisDefinitionInterface;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class AnalysisDefinitionOptionsBuilder
{
    public function __construct(
        private StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
    ) {
    }

    /**
     * @param list<AnalysisDefinitionInterface> $allDefinitions
     *
     * @return array{
     *   definitions: list<AnalysisDefinitionInterface>,
     *   urls: array<string, string>
     * }
     */
    public function build(Request $request, array $allDefinitions): array
    {
        $analysisDefinitions = [];
        $analysisSelectUrls = [];

        foreach ($allDefinitions as $definition) {
            if ($this->isLegacyKey($definition->key())) {
                continue;
            }

            $analysisDefinitions[] = $definition;
            $analysisSelectUrls[$definition->key()] = $this->statisticsNavigationUrlBuilder->build(
                $request,
                'app_stats_pivot_tables',
                ['analysis' => $definition->key()],
            );
        }

        return [
            'definitions' => $analysisDefinitions,
            'urls' => $analysisSelectUrls,
        ];
    }

    private function isLegacyKey(string $key): bool
    {
        return 'pivot' === $key || 'allocations_over_time' === $key;
    }
}
