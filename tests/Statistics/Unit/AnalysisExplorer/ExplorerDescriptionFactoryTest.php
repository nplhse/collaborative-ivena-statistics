<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerDescriptionFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerTitleFactory;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExplorerDescriptionFactoryTest extends TestCase
{
    public function testDescriptionForGenderTotal(): void
    {
        $factory = $this->factory([
            'stats.analysis_explorer.description.breakdown_total' => 'Allocation counts grouped by {dimension}.',
            'stats.analysis_explorer.allocations_by_dimension' => 'Allocations by gender',
            'stats.analysis_explorer.dimension.gender' => 'gender',
        ]);

        self::assertSame(
            'Allocation counts grouped by Allocations by gender.',
            $factory->descriptionForAxes(
                AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
                null,
                ChartPresentationType::Bar,
            ),
        );
    }

    public function testDescriptionForGenderOverTimeUsesGrainLabel(): void
    {
        $factory = $this->factory([
            'stats.analysis_explorer.description.breakdown_over_time' => '{grain} allocation counts split by {dimension}, shown as {chart}.',
            'stats.analysis_explorer.description.grain.month' => 'Monthly',
            'stats.analysis_explorer.allocations_by_dimension' => 'Allocations by gender',
            'stats.analysis_explorer.dimension.gender' => 'gender',
            'stats.analysis_explorer.chart.grouped_bar' => 'grouped bar chart',
        ]);

        self::assertSame(
            'Monthly allocation counts split by Allocations by gender, shown as grouped bar chart.',
            $factory->descriptionForAxes(
                AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
                AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
                ChartPresentationType::GroupedBar,
            ),
        );
    }

    public function testDescriptionForTimeYearLineUsesDedicatedText(): void
    {
        $factory = $this->factory([
            'stats.analysis_explorer.description.temporal_primary' => '{grain} allocation totals.',
            'stats.analysis_explorer.description.grain.year' => 'Yearly',
        ]);

        self::assertSame(
            'Yearly allocation totals.',
            $factory->descriptionForAxes(
                AnalysisAxisRef::time(AnalysisDimensionGrain::Year),
                null,
                ChartPresentationType::Line,
            ),
        );
    }

    /**
     * @param array<string, string> $map
     */
    private function factory(array $map): ExplorerDescriptionFactory
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = []) use ($map): string {
                $template = $map[$id] ?? $id;

                return strtr($template, array_combine(
                    array_map(static fn (string $key): string => '{'.$key.'}', array_keys($parameters)),
                    array_values($parameters),
                ));
            },
        );

        return new ExplorerDescriptionFactory($translator, new ExplorerTitleFactory($translator));
    }
}
