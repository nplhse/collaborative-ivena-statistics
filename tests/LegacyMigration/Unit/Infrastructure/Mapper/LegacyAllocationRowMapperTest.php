<?php

declare(strict_types=1);

namespace App\Tests\LegacyMigration\Unit\Infrastructure\Mapper;

use App\LegacyMigration\Infrastructure\Mapper\LegacyAllocationRowMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyAllocationRowMapperTest extends TestCase
{
    private LegacyAllocationRowMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new LegacyAllocationRowMapper();
    }

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('secondaryIndicationCodeProvider')]
    public function testMapsSecondaryIndicationCodeAndText(array $row, ?int $expectedCode, ?string $expectedText): void
    {
        $dto = $this->mapper->mapAssoc($row);

        self::assertSame($expectedCode, $dto->secondaryIndicationCode);
        self::assertSame($expectedText, $dto->secondaryIndication);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int|null, string|null}>
     */
    public static function secondaryIndicationCodeProvider(): iterable
    {
        $base = [];

        yield 'missing secondary code and text' => [
            $base,
            null,
            null,
        ];

        yield 'null secondary code' => [
            array_merge($base, ['secondary_indication_code' => null, 'secondary_indication' => 'Some text']),
            null,
            'Some text',
        ];

        yield 'six digit legacy code maps to first three digits' => [
            array_merge($base, [
                'secondary_indication_code' => 376_802,
                'secondary_indication' => '(SARS-CoV-2) Covid-19 bestätigt',
            ]),
            376,
            '(SARS-CoV-2) Covid-19 bestätigt',
        ];

        yield 'three digit code passthrough' => [
            array_merge($base, ['secondary_indication_code' => 450, 'secondary_indication' => 'Label']),
            450,
            'Label',
        ];

        yield 'three digit boundary min' => [
            array_merge($base, ['secondary_indication_code' => 100, 'secondary_indication' => 'X']),
            100,
            'X',
        ];

        yield 'three digit boundary max' => [
            array_merge($base, ['secondary_indication_code' => 999, 'secondary_indication' => 'X']),
            999,
            'X',
        ];

        yield 'below three digit range yields null code' => [
            array_merge($base, ['secondary_indication_code' => 99, 'secondary_indication' => 'X']),
            null,
            'X',
        ];

        yield 'between 1000 and 99999 yields null code' => [
            array_merge($base, ['secondary_indication_code' => 50_000, 'secondary_indication' => 'X']),
            null,
            'X',
        ];

        yield 'above six digit max yields null code' => [
            array_merge($base, ['secondary_indication_code' => 1_000_000, 'secondary_indication' => 'X']),
            null,
            'X',
        ];
    }
}
