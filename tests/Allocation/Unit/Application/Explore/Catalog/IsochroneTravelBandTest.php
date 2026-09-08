<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Explore\Catalog;

use App\Allocation\Application\Explore\Catalog\IsochroneTravelBand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IsochroneTravelBandTest extends TestCase
{
    public function testMinutesBetweenReturnsNullWhenTimestampsAreMissing(): void
    {
        $createdAt = new \DateTimeImmutable('2025-01-01 10:00:00');

        self::assertNull(IsochroneTravelBand::minutesBetween(null, $createdAt));
        self::assertNull(IsochroneTravelBand::minutesBetween($createdAt, null));
        self::assertNull(IsochroneTravelBand::minutesBetween(null, null));
    }

    public function testMinutesBetweenRoundsToNearestMinute(): void
    {
        $createdAt = new \DateTimeImmutable('2025-01-01 10:00:00');
        $arrivalAt = $createdAt->modify('+8 minutes +20 seconds');

        self::assertSame(8, IsochroneTravelBand::minutesBetween($createdAt, $arrivalAt));
    }

    public function testMinutesBetweenReturnsNullWhenArrivalIsBeforeCreated(): void
    {
        $createdAt = new \DateTimeImmutable('2025-01-01 10:00:00');
        $arrivalAt = $createdAt->modify('-1 minute');

        self::assertNull(IsochroneTravelBand::minutesBetween($createdAt, $arrivalAt));
    }

    #[DataProvider('highlightMinutesProvider')]
    public function testHighlightMinutesMapsRecordedDurationOntoFiveMinuteBands(
        ?int $recordedMinutes,
        ?int $expectedBand,
        bool $exceedsMaximum,
    ): void {
        self::assertSame($expectedBand, IsochroneTravelBand::highlightMinutes($recordedMinutes));
        self::assertSame($exceedsMaximum, IsochroneTravelBand::exceedsMaximum($recordedMinutes));
    }

    /**
     * @return iterable<string, array{0: ?int, 1: ?int, 2: bool}>
     */
    public static function highlightMinutesProvider(): iterable
    {
        yield 'missing' => [null, null, false];
        yield 'zero' => [0, null, false];
        yield 'negative' => [-4, null, false];
        yield 'one_minute' => [1, 5, false];
        yield 'eight_minutes' => [8, 10, false];
        yield 'ten_minutes' => [10, 10, false];
        yield 'fifty_minutes' => [50, 50, false];
        yield 'over_fifty' => [51, 50, true];
    }

    public function testCollectionForRecordedMinutesKeepsMatchingBandOnly(): void
    {
        $five = [
            'type' => 'Feature',
            'properties' => ['value' => 300],
            'geometry' => ['type' => 'Polygon', 'coordinates' => []],
        ];
        $ten = [
            'type' => 'Feature',
            'properties' => ['value' => 600],
            'geometry' => ['type' => 'Polygon', 'coordinates' => []],
        ];
        $catalog = [
            'type' => 'FeatureCollection',
            'features' => [$five, $ten],
        ];

        $band = IsochroneTravelBand::collectionForRecordedMinutes($catalog, 8);

        self::assertIsArray($band);
        self::assertSame([$ten], $band['features']);
    }

    public function testCollectionForRecordedMinutesReturnsNullWithoutMatch(): void
    {
        $catalog = [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'properties' => ['value' => 300],
                    'geometry' => ['type' => 'Polygon', 'coordinates' => []],
                ],
            ],
        ];

        self::assertNull(IsochroneTravelBand::collectionForRecordedMinutes($catalog, 8));
        self::assertNull(IsochroneTravelBand::collectionForRecordedMinutes($catalog, null));
    }
}
