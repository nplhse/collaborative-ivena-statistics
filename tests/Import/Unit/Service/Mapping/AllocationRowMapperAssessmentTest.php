<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Service\Mapping;

use App\Import\Infrastructure\Mapping\AllocationRowMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllocationRowMapperAssessmentTest extends TestCase
{
    private AllocationRowMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new AllocationRowMapper();
    }

    /**
     * @param array<string, string> $row
     */
    #[DataProvider('placeholderAssessmentRowProvider')]
    public function testPlaceholderAssessmentValuesMapToNull(array $row): void
    {
        $dto = $this->mapper->mapAssoc($row);

        self::assertNull($dto->assessmentAirway);
        self::assertNull($dto->assessmentBreathing);
        self::assertNull($dto->assessmentCirculation);
        self::assertNull($dto->assessmentDisability);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function placeholderAssessmentRowProvider(): iterable
    {
        yield 'abcd prefix only' => [[
            'airway' => 'A-',
            'breathing' => 'B-',
            'circulation' => 'C-',
            'disability' => 'D-',
        ]];
        yield 'empty cells' => [[
            'airway' => '',
            'breathing' => '',
            'circulation' => '',
            'disability' => '',
        ]];
        yield 'whitespace only' => [[
            'airway' => '   ',
            'breathing' => '   ',
            'circulation' => '   ',
            'disability' => '   ',
        ]];
    }

    public function testValidGermanAssessmentValuesAreMapped(): void
    {
        $dto = $this->mapper->mapAssoc([
            'airway' => 'A-Frei',
            'breathing' => 'B-Spontan',
            'circulation' => 'C-Stabil',
            'disability' => 'D-Wach',
        ]);

        self::assertSame('free', $dto->assessmentAirway);
        self::assertSame('spontaneous', $dto->assessmentBreathing);
        self::assertSame('stable', $dto->assessmentCirculation);
        self::assertSame('awake', $dto->assessmentDisability);
    }

    /**
     * @param array<string, string> $row
     */
    #[DataProvider('frequentLogCombinationProvider')]
    public function testFrequentLogCombinationValuesAreMapped(
        array $row,
        string $dtoProperty,
        string $expected,
    ): void {
        $dto = $this->mapper->mapAssoc($row);

        self::assertSame($expected, $dto->{$dtoProperty});
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function frequentLogCombinationProvider(): iterable
    {
        yield 'disability GCS <15' => [
            ['disability' => 'D-GCS <15'],
            'assessmentDisability',
            'gcs_below_15',
        ];
        yield 'circulation Kritisch' => [
            ['circulation' => 'C-Kritisch'],
            'assessmentCirculation',
            'unstable',
        ];
        yield 'breathing Kritisch' => [
            ['breathing' => 'B-Kritisch'],
            'assessmentBreathing',
            'insufficient',
        ];
        yield 'circulation Katecholamin' => [
            ['circulation' => 'C-Katecholamin'],
            'assessmentCirculation',
            'medication',
        ];
        yield 'breathing Flow-CPAP' => [
            ['breathing' => 'B-Flow-CPAP'],
            'assessmentBreathing',
            'cpap',
        ];
        yield 'airway Kritisch' => [
            ['airway' => 'A-Kritisch'],
            'assessmentAirway',
            'critical',
        ];
        yield 'circulation Laufende Reanimation' => [
            ['circulation' => 'C-Laufende Reanimation'],
            'assessmentCirculation',
            'cpr',
        ];
    }
}
