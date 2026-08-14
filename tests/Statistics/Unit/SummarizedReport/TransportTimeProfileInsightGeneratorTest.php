<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\SummarizedReport;

use App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileCell;
use App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileMatrixRow;
use App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileMatrixSection;
use App\Statistics\Application\SummarizedReport\TransportTimeProfile\TransportTimeProfileAssembler;
use App\Statistics\Application\SummarizedReport\TransportTimeProfile\TransportTimeProfileInsightGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TransportTimeProfileInsightGeneratorTest extends TestCase
{
    public function testStrongUrgencyPatternProducesDescriptiveInsight(): void
    {
        $insights = $this->generator()->generate(
            $this->volume(100),
            [$this->percentSection('urgency', '1', ['under_10' => 10.0, 'over_60' => 32.0])],
            $this->bucketLabels(),
            700,
            'en',
        );

        self::assertNotEmpty($insights);
        self::assertSame('urgency_emergency', $insights[0]->id);
        self::assertStringContainsString('rate_increase', $insights[0]->body);
        self::assertStringNotContainsString('require', strtolower($insights[0]->body));
        self::assertStringNotContainsString('cause', strtolower($insights[0]->body));
    }

    public function testTrivialChangeDoesNotProduceInsight(): void
    {
        $insights = $this->generator()->generate(
            $this->volume(100),
            [$this->percentSection('urgency', '1', ['under_10' => 14.0, 'over_60' => 16.0])],
            $this->bucketLabels(),
            700,
            'en',
        );

        self::assertSame([], $insights);
    }

    public function testInsufficientSampleSuppressesInsights(): void
    {
        $insights = $this->generator()->generate(
            ['under_10' => 8, 'over_60' => 8],
            [$this->percentSection('urgency', '1', ['under_10' => 10.0, 'over_60' => 40.0])],
            $this->bucketLabels(),
            16,
            'en',
        );

        self::assertSame([], $insights);
    }

    public function testRankChangeRequiresBothRankAndShareMovement(): void
    {
        $section = new TransportTimeProfileMatrixSection('departments', 'departments', [
            new TransportTimeProfileMatrixRow('rank_1', '#1', 'ranked', [
                'under_10' => $this->rankedCell(1, 'Neurology', 20.0, 1),
                'over_60' => $this->rankedCell(3, 'Neurology', 32.0, 1),
            ]),
            new TransportTimeProfileMatrixRow('rank_3', '#3', 'ranked', [
                'under_10' => $this->rankedCell(3, 'Internal', 18.0, 2),
                'over_60' => $this->rankedCell(1, 'Internal', 19.0, 2),
            ]),
        ]);

        $insights = $this->generator()->generate(
            $this->volume(100),
            [$section],
            $this->bucketLabels(),
            700,
            'en',
        );

        $ids = array_map(static fn (\App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileInsight $insight): string => $insight->id, $insights);
        self::assertContains('departments_1', $ids);
        self::assertNotContains('departments_2', $ids);
    }

    public function testCathlabRateSpanUsesResourcesSection(): void
    {
        $insights = $this->generator()->generate(
            $this->volume(100),
            [$this->percentSection('resources', 'requires_cathlab', ['under_10' => 4.0, 'over_60' => 22.0])],
            $this->bucketLabels(),
            700,
            'en',
        );

        self::assertNotEmpty($insights);
        self::assertSame('cathlab', $insights[0]->id);
    }

    public function testRateDecreaseAndAirOverrepresentationProduceInsights(): void
    {
        $insights = $this->generator()->generate(
            $this->volume(100),
            [
                $this->percentSection('urgency', '1', ['under_10' => 32.0, 'over_60' => 10.0]),
                $this->percentSection('transport_mode', '2', [
                    'under_10' => 5.0,
                    '10_20' => 5.0,
                    '20_30' => 5.0,
                    '30_40' => 5.0,
                    '40_50' => 5.0,
                    '50_60' => 5.0,
                    'over_60' => 20.0,
                ]),
            ],
            $this->bucketLabels(),
            700,
            'en',
        );

        $ids = array_map(static fn (\App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileInsight $insight): string => $insight->id, $insights);
        self::assertContains('urgency_emergency', $ids);
        self::assertStringContainsString('rate_decrease', $insights[0]->body);
        self::assertContains('air_overrepresented', $ids);
        self::assertLessThanOrEqual(4, \count($insights));
    }

    private function generator(): TransportTimeProfileInsightGenerator
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => $id,
        );

        return new TransportTimeProfileInsightGenerator(
            $translator,
            new TransportTimeProfileAssembler($translator),
        );
    }

    /**
     * @param array<string, float> $percents
     */
    private function percentSection(string $sectionKey, string $rowKey, array $percents): TransportTimeProfileMatrixSection
    {
        $cells = [];
        foreach (['under_10', '10_20', '20_30', '30_40', '40_50', '50_60', 'over_60'] as $bucket) {
            $percent = $percents[$bucket] ?? 12.0;
            $cells[$bucket] = new TransportTimeProfileCell(100, false, count: 12, percent: $percent);
        }

        return new TransportTimeProfileMatrixSection($sectionKey, $sectionKey, [
            new TransportTimeProfileMatrixRow($rowKey, $rowKey, 'percent', $cells, 12.0),
        ]);
    }

    private function rankedCell(int $rank, string $label, float $percent, int $id): TransportTimeProfileCell
    {
        return new TransportTimeProfileCell(
            100,
            false,
            count: (int) round($percent),
            percent: $percent,
            rank: $rank,
            entityLabel: $label,
            entityId: $id,
        );
    }

    /**
     * @return array<string, int>
     */
    private function volume(int $n): array
    {
        return [
            'under_10' => $n,
            '10_20' => $n,
            '20_30' => $n,
            '30_40' => $n,
            '40_50' => $n,
            '50_60' => $n,
            'over_60' => $n,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function bucketLabels(): array
    {
        return [
            'under_10' => 'Under 10 min',
            'over_60' => 'Over 60 min',
        ];
    }
}
