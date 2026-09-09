<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\TopList;

use App\Statistics\Application\TopList\TopListComparison;
use App\Statistics\Application\TopList\TopListComparisonAssembler;
use App\Statistics\Application\TopList\TopListComparisonRow;
use App\Statistics\Application\TopList\TopListDefinitionInterface;
use App\Statistics\Application\TopList\TopListExportBuilder;
use App\Statistics\Application\TopList\TopListLimit;
use App\Statistics\Application\TopList\TopListRankedRow;
use App\Statistics\Application\TopList\TopListRanking;
use App\Statistics\GenericAnalysis\Application\Export\CsvTabularExporter;
use App\Statistics\GenericAnalysis\Application\Export\TabularExportDocument;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TopListExportBuilderTest extends TestCase
{
    public function testBuildsRegularRankingWithContextColumns(): void
    {
        $csv = $this->exportToString($this->builder()->build(
            $this->definition(),
            new TopListRanking([
                new TopListRankedRow('1', 'STEMI', 8, 50.0, 1, 1),
                new TopListRankedRow('2', 'Stroke', 8, 50.0, 2, 2),
            ], 16),
            null,
            'Public',
            'Last 12 months',
        ));

        $lines = $this->csvLines($csv);
        self::assertSame(['Scope', 'Period', 'Rank', 'Indication', 'Count', 'Share'], $lines[0]);
        self::assertSame(['Public', 'Last 12 months', '1', 'STEMI', '8', '50'], $lines[1]);
        self::assertSame(['Public', 'Last 12 months', '2', 'Stroke', '8', '50'], $lines[2]);
        self::assertCount(3, $lines);
    }

    public function testBuildsEmptyRankingWithHeadersOnly(): void
    {
        $csv = $this->exportToString($this->builder()->build(
            $this->definition(),
            new TopListRanking([], 0),
            null,
            'Public',
            '2024',
        ));

        $lines = $this->csvLines($csv);
        self::assertCount(1, $lines);
        self::assertSame('Rank', $lines[0][2]);
    }

    public function testBuildsMergedComparisonIncludingOnlyInOneSideAndRelativeDelta(): void
    {
        $rankingA = new TopListRanking([
            new TopListRankedRow('1', 'Alpha', 20, 50.0, 1, 1),
            new TopListRankedRow('2', 'Beta', 10, 25.0, 2, 2),
            new TopListRankedRow('3', 'Gamma', 5, 12.5, 3, 3),
        ], 40);
        $rankingB = new TopListRanking([
            new TopListRankedRow('2', 'Beta', 30, 60.0, 1, 2),
            new TopListRankedRow('1', 'Alpha', 15, 30.0, 2, 1),
            new TopListRankedRow('4', 'Delta', 5, 10.0, 3, 4),
        ], 50);
        $comparison = new TopListComparisonAssembler()->assemble($rankingA, $rankingB);

        $csv = $this->exportToString($this->builder()->build(
            $this->definition(),
            $rankingA,
            $comparison,
            'Public',
            '2024',
            'Public',
            'Last 12 months',
        ));

        $lines = $this->csvLines($csv);
        self::assertSame('Scope A', $lines[0][0]);
        self::assertSame('Period A', $lines[0][1]);
        self::assertSame('Scope B', $lines[0][2]);
        self::assertSame('Period B', $lines[0][3]);
        self::assertSame('Indication', $lines[0][4]);
        self::assertSame('Relative delta', $lines[0][13]);
        self::assertSame('Rank change', $lines[0][14]);

        $byLabel = [];
        foreach (\array_slice($lines, 1) as $row) {
            $byLabel[$row[4]] = $row;
        }

        self::assertSame(['Alpha', 'Beta', 'Gamma', 'Delta'], array_keys($byLabel));
        self::assertSame('1', $byLabel['Alpha'][5]);
        self::assertSame('20', $byLabel['Alpha'][6]);
        self::assertSame('2', $byLabel['Alpha'][8]);
        self::assertSame('15', $byLabel['Alpha'][9]);
        self::assertSame('-5', $byLabel['Alpha'][11]);
        self::assertSame('-0.25', $byLabel['Alpha'][13]);
        self::assertSame('-1', $byLabel['Alpha'][14]);

        self::assertSame('', $byLabel['Gamma'][8]);
        self::assertSame('', $byLabel['Gamma'][13]);
        self::assertSame('3', $byLabel['Delta'][8]);
        self::assertSame('5', $byLabel['Delta'][9]);
        self::assertSame('', $byLabel['Delta'][5]);
        self::assertSame('', $byLabel['Delta'][13]);
    }

    public function testRelativeDeltaIsBlankWhenCountAIsZero(): void
    {
        $comparison = new TopListComparison(
            [
                new TopListComparisonRow(
                    '1',
                    'Zero',
                    1,
                    0,
                    0.0,
                    1,
                    8,
                    100.0,
                    8,
                    100.0,
                    0,
                    false,
                    false,
                ),
            ],
            [],
            0,
            8,
        );

        $csv = $this->exportToString($this->builder()->build(
            $this->definition(),
            new TopListRanking([], 0),
            $comparison,
            'Public',
            '2024',
            'Public',
            'Last 12 months',
        ));

        $lines = $this->csvLines($csv);
        self::assertSame('Zero', $lines[1][4]);
        self::assertSame('', $lines[1][13]);
    }

    public function testFilenameTitleIncludesLimitAndComparison(): void
    {
        $builder = $this->builder();
        $definition = $this->definition();

        self::assertSame('top_diagnoses top-25', $builder->filenameTitle($definition, TopListLimit::of(25), false));
        self::assertSame('top_diagnoses all comparison', $builder->filenameTitle($definition, TopListLimit::all(), true));
    }

    private function builder(): TopListExportBuilder
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => match ($id) {
                'stats.filter.scope_label' => 'Scope',
                'stats.filter.period_label' => 'Period',
                'stats.top_lists.export.scope_a' => 'Scope A',
                'stats.top_lists.export.period_a' => 'Period A',
                'stats.top_lists.export.scope_b' => 'Scope B',
                'stats.top_lists.export.period_b' => 'Period B',
                'stats.top_lists.table.rank' => 'Rank',
                'stats.top_lists.table.diagnosis' => 'Indication',
                'stats.top_lists.table.count' => 'Count',
                'stats.top_lists.table.share' => 'Share',
                'stats.top_lists.comparison.rank_a' => 'Rank A',
                'stats.top_lists.comparison.count_a' => 'Count A',
                'stats.top_lists.comparison.share_a' => 'Share A',
                'stats.top_lists.comparison.rank_b' => 'Rank B',
                'stats.top_lists.comparison.count_b' => 'Count B',
                'stats.top_lists.comparison.share_b' => 'Share B',
                'stats.top_lists.comparison.delta_count' => 'Δ Count',
                'stats.top_lists.comparison.delta_share' => 'Δ Share',
                'stats.top_lists.export.relative_delta' => 'Relative delta',
                'stats.top_lists.comparison.rank_movement' => 'Rank change',
                default => $id,
            },
        );

        return new TopListExportBuilder($translator);
    }

    private function definition(): TopListDefinitionInterface
    {
        $definition = $this->createStub(TopListDefinitionInterface::class);
        $definition->method('key')->willReturn('top_diagnoses');
        $definition->method('tableLabelColumnTranslationKey')->willReturn('stats.top_lists.table.diagnosis');

        return $definition;
    }

    /**
     * @return list<list<string>>
     */
    private function csvLines(string $csv): array
    {
        $body = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
        $lines = preg_split('/\R/', trim($body));
        self::assertIsArray($lines);

        return array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', '\\'),
            $lines,
        );
    }

    private function exportToString(TabularExportDocument $document): string
    {
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);

        try {
            new CsvTabularExporter()->export($document, $stream);
            rewind($stream);
            $csv = stream_get_contents($stream);
            self::assertIsString($csv);

            return $csv;
        } finally {
            fclose($stream);
        }
    }
}
