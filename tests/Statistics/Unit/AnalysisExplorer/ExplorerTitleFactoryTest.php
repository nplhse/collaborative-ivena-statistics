<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerTitleFactory;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExplorerTitleFactoryTest extends TestCase
{
    public function testTitleForGenderTotalUsesSummedTitle(): void
    {
        $factory = new ExplorerTitleFactory($this->translator([
            'stats.analysis_explorer.allocations_by_dimension' => 'Allocations by {dimension}',
            'stats.analysis_explorer.dimension.gender' => 'gender',
        ]));

        self::assertSame(
            'Allocations by gender',
            $factory->titleForAxes(AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender), null),
        );
    }

    public function testTitleForGenderMonthUsesOverTimeTitle(): void
    {
        $factory = new ExplorerTitleFactory($this->translator([
            'stats.analysis_explorer.allocations_by_dimension_over_time' => 'Allocations by {dimension} over time',
            'stats.analysis_explorer.dimension.gender' => 'gender',
        ]));

        self::assertSame(
            'Allocations by gender over time',
            $factory->titleForAxes(
                AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
                AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            ),
        );
    }

    public function testTitleForAgeGroupByMonthUsesCrossTabTitle(): void
    {
        $factory = new ExplorerTitleFactory($this->translator([
            'stats.analysis_explorer.allocations_by_dimension_by_temporal' => '{dimension} by {temporal}',
            'stats.analysis_explorer.dimension.age_group' => 'age group',
            'stats.analysis_explorer.dimension.month' => 'month',
        ]));

        self::assertSame(
            'age group by month',
            $factory->titleForAxes(
                AnalysisAxisRef::breakdown(AnalysisDimensionKey::AgeGroup),
                AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            ),
        );
    }

    /**
     * @param array<string, string> $map
     */
    private function translator(array $map): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = []) use ($map): string {
                $template = $map[$id] ?? $id;

                return strtr($template, array_combine(
                    array_map(static fn (string $key): string => '{'.$key.'}', array_keys($parameters)),
                    array_values($parameters),
                ));
            },
        );

        return $translator;
    }
}
