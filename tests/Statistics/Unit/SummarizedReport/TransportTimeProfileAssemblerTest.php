<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\SummarizedReport;

use App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileSliceData;
use App\Statistics\Application\SummarizedReport\TransportTimeProfile\TransportTimeProfileAssembler;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TransportTimeProfileAssemblerTest extends TestCase
{
    public function testWithinBucketPercentUsesBucketNAsDenominator(): void
    {
        $assembler = new TransportTimeProfileAssembler($this->translator());
        $slice = $this->sliceWithUrgency([
            'under_10' => ['1' => 10, '2' => 90],
            'over_60' => ['1' => 40, '2' => 60],
        ], [
            'under_10' => 100,
            'over_60' => 100,
        ]);

        $matrix = $assembler->matrix($slice, 'en', [], [], [], [], '', '');
        $emergency = $this->row($matrix, 'urgency', '1');

        self::assertSame(10.0, $emergency->cells['under_10']->percent);
        self::assertSame(40.0, $emergency->cells['over_60']->percent);
        self::assertSame(25.0, $emergency->overallPercent);
        self::assertSame(-15.0, $emergency->cells['under_10']->deltaPp);
        self::assertSame(15.0, $emergency->cells['over_60']->deltaPp);
    }

    public function testTopFiveIsRankedIndependentlyPerBucket(): void
    {
        $assembler = new TransportTimeProfileAssembler($this->translator());
        $slice = new TransportTimeProfileSliceData(
            0,
            ['under_10' => 100, 'over_60' => 50],
            [],
            [],
            [],
            [],
            [],
            [],
            [
                'under_10' => [
                    ['id' => 1, 'count' => 40],
                    ['id' => 2, 'count' => 30],
                    ['id' => 3, 'count' => 10],
                ],
                'over_60' => [
                    ['id' => 3, 'count' => 25],
                    ['id' => 1, 'count' => 10],
                    ['id' => 4, 'count' => 8],
                ],
            ],
            [],
            [],
        );

        $matrix = $assembler->matrix(
            $slice,
            'en',
            [1 => 'Internal', 2 => 'Surgery', 3 => 'Neurology', 4 => 'Trauma'],
            [],
            [],
            [],
            '/departments',
            '',
        );
        $rank1 = $this->row($matrix, 'departments', 'rank_1');
        $rank3 = $this->row($matrix, 'departments', 'rank_3');

        self::assertSame('Internal', $rank1->cells['under_10']->entityLabel);
        self::assertSame('Neurology', $rank1->cells['over_60']->entityLabel);
        self::assertSame(2, $rank1->cells['over_60']->rankDelta);
        self::assertSame('Neurology', $rank3->cells['under_10']->entityLabel);
        self::assertTrue($rank3->cells['over_60']->enteredTop);
        self::assertSame('Trauma', $rank3->cells['over_60']->entityLabel);
        self::assertSame(40.0, $rank1->cells['under_10']->percent);
        self::assertSame(50.0, $rank1->cells['over_60']->percent);
    }

    public function testSmallSampleIsFlaggedBelowTen(): void
    {
        $assembler = new TransportTimeProfileAssembler($this->translator());
        $slice = $this->sliceWithUrgency([
            'under_10' => ['1' => 4],
            'over_60' => ['1' => 20],
        ], [
            'under_10' => 9,
            'over_60' => 40,
        ]);

        $matrix = $assembler->matrix($slice, 'en', [], [], [], [], '', '');
        $emergency = $this->row($matrix, 'urgency', '1');

        self::assertTrue($emergency->cells['under_10']->smallSample);
        self::assertFalse($emergency->cells['over_60']->smallSample);
        self::assertSame(4, $emergency->cells['under_10']->count);
    }

    public function testResourcesSectionContainsResusAndCathlabRows(): void
    {
        $assembler = new TransportTimeProfileAssembler($this->translator());
        $slice = new TransportTimeProfileSliceData(
            0,
            ['under_10' => 100, 'over_60' => 50],
            [],
            [],
            [],
            ['under_10' => ['1' => 20], 'over_60' => ['1' => 5]],
            ['under_10' => ['1' => 10], 'over_60' => ['1' => 25]],
            [],
            [],
            [],
            [],
        );

        $matrix = $assembler->matrix($slice, 'en', [], [], [], [], '', '');
        $resus = $this->row($matrix, 'resources', 'requires_resus');
        $cathlab = $this->row($matrix, 'resources', 'requires_cathlab');

        self::assertSame(20.0, $resus->cells['under_10']->percent);
        self::assertSame(10.0, $resus->cells['over_60']->percent);
        self::assertSame(10.0, $cathlab->cells['under_10']->percent);
        self::assertSame(50.0, $cathlab->cells['over_60']->percent);
    }

    public function testChartSpecsIncludeCasesGenderAndUrgencyModes(): void
    {
        $assembler = new TransportTimeProfileAssembler($this->translator());
        $slice = $this->sliceWithUrgency([
            'under_10' => ['1' => 10],
            'over_60' => ['1' => 40],
        ], [
            'under_10' => 100,
            'over_60' => 100,
        ]);

        $specs = $assembler->chartSpecs($slice, $this->bucketLabels(), 'en');

        self::assertSame('bar', $specs['cases']['chartType']);
        self::assertSame([100, 0, 0, 0, 0, 0, 100], $specs['cases']['counts']);
        self::assertSame('Under 10 min', $specs['cases']['labels'][0]);
        self::assertArrayHasKey('series', $specs['gender']);
        self::assertArrayHasKey('series', $specs['urgency']);
        self::assertSame($specs['cases']['labels'], $specs['gender']['labels']);
    }

    public function testMissingRankedBucketIsEmptyAndIndicationUsesEntityUrl(): void
    {
        $assembler = new TransportTimeProfileAssembler($this->translator());
        $slice = new TransportTimeProfileSliceData(
            0,
            ['under_10' => 40],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [
                'under_10' => [
                    ['id' => 9, 'count' => 12],
                ],
            ],
        );

        $matrix = $assembler->matrix(
            $slice,
            'en',
            [],
            [],
            [9 => 'STEMI'],
            [9 => '/indications/9'],
            '/departments',
            '/specialities',
        );
        $rank1 = $this->row($matrix, 'indications', 'rank_1');

        self::assertSame('STEMI', $rank1->cells['under_10']->entityLabel);
        self::assertSame('/indications/9', $rank1->cells['under_10']->linkUrl);
        self::assertTrue($rank1->cells['over_60']->isEmpty());
        self::assertSame([], $assembler->rateSeriesByRow($matrix, 'missing', 'row'));
        self::assertSame(
            ['under_10' => 40, '10_20' => 0, '20_30' => 0, '30_40' => 0, '40_50' => 0, '50_60' => 0, 'over_60' => 0],
            $assembler->volumeByBucket($slice),
        );
    }

    /**
     * @param array<string, array<int|string, int>> $urgency
     * @param array<string, int>                    $volume
     */
    private function sliceWithUrgency(array $urgency, array $volume): TransportTimeProfileSliceData
    {
        return new TransportTimeProfileSliceData(
            0,
            $volume,
            [],
            $urgency,
            [],
            [],
            [],
            [],
            [],
            [],
            [],
        );
    }

    /**
     * @return array<string, string>
     */
    private function bucketLabels(): array
    {
        return [
            'under_10' => 'Under 10 min',
            '10_20' => '10–20 min',
            '20_30' => '20–30 min',
            '30_40' => '30–40 min',
            '40_50' => '40–50 min',
            '50_60' => '50–60 min',
            'over_60' => 'Over 60 min',
        ];
    }

    /**
     * @param list<\App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileMatrixSection> $sections
     */
    private function row(array $sections, string $sectionKey, string $rowKey): \App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileMatrixRow
    {
        foreach ($sections as $section) {
            if ($section->key !== $sectionKey) {
                continue;
            }
            foreach ($section->rows as $row) {
                if ($row->key === $rowKey) {
                    return $row;
                }
            }
        }

        self::fail(sprintf('Row %s/%s not found.', $sectionKey, $rowKey));
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $parameters['percent'] ?? $id,
        );

        return $translator;
    }
}
