<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Statistics;

use App\Model\Scope;
use App\Service\Statistics\HourlyChartDataLoader;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class HourlyChartDataLoaderTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $db;

    protected function setUp(): void
    {
        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        $this->db = $db;
    }

    public function testBuildPayloadParsesAvailableAndBuildsSeriesForGivenMetrics(): void
    {
        // Arrange
        $hours = [
            'total' => array_fill(0, 24, 1),
            'gender_m' => range(0, 23),
            'cathlab_required' => array_fill(0, 24, 2),
        ];
        $this->db->method('fetchAssociative')->willReturn([
            'hours_count' => json_encode($hours),
        ]);

        $sut = new HourlyChartDataLoader($this->db);
        $scope = new Scope('hospital', '123', 'day', '2025-11-08');

        // Act
        $out = $sut->buildPayload($scope, ['total', 'gender_m', 'cathlab', 'resus']);

        // Assert
        self::assertSame(range(0, 23), array_map('intval', $out['labels'])); // "00".."23" -> ints 0..23
        $names = array_column($out['series'], 'name');
        self::assertSame(['Total', 'Male', 'Cathlab required', 'Resus required'], $names);

        $byName = [];
        foreach ($out['series'] as $s) {
            $byName[$s['name']] = $s['data'];
        }
        self::assertCount(24, $byName['Total']);
        self::assertSame(range(0, 23), $byName['Male']);
        self::assertSame(array_fill(0, 24, 2), $byName['Cathlab required']);
        self::assertSame(array_fill(0, 24, 0), $byName['Resus required']); // missing -> zero padded

        self::assertSame(['total', 'gender_m', 'cathlab_required'], $out['meta']['available']);
        self::assertSame('hospital', $out['meta']['scopeType']);
        self::assertSame('123', $out['meta']['scopeId']);
        self::assertSame('day', $out['meta']['gran']);
        self::assertSame('2025-11-08', $out['meta']['key']);
    }

    public function testBuildPayloadAcceptsArrayNotJsonAndNormalizesLengths(): void
    {
        // Arrange: 26 values -> must be sliced to 24; 3 values -> must be padded to 24
        $this->db->method('fetchAssociative')->willReturn([
            'hours_count' => [
                'total' => range(0, 25),
                'gender_w' => [5, 6, 7],
            ],
        ]);

        $sut = new HourlyChartDataLoader($this->db);
        $scope = new Scope('public', 'x', 'day', '2025-11-08');

        // Act
        $out = $sut->buildPayload($scope, ['total', 'gender_w']);

        // Assert
        $series = $out['series'];
        self::assertCount(2, $series);
        self::assertCount(24, $series[0]['data']);
        self::assertSame(range(0, 23), $series[0]['data']); // sliced
        self::assertSame(array_merge([5, 6, 7], array_fill(0, 21, 0)), $series[1]['data']); // padded
    }

    public function testBuildPayloadWhenNoRowOrNoHoursCountYieldsZeroSeries(): void
    {
        // Arrange
        $this->db->method('fetchAssociative')->willReturn(false);
        $sut = new HourlyChartDataLoader($this->db);
        $scope = new Scope('state', 'BY', 'day', '2025-11-08');

        // Act
        $out = $sut->buildPayload($scope, ['total']);

        // Assert
        self::assertSame([array_fill(0, 24, 0)], array_column($out['series'], 'data'));
        self::assertSame([], $out['meta']['available']);
    }

    public function testBuildPayloadInvalidJsonThrows(): void
    {
        // Arrange
        $this->db->method('fetchAssociative')->willReturn(['hours_count' => '{not json}']);
        $sut = new HourlyChartDataLoader($this->db);
        $scope = new Scope('state', 'BY', 'day', '2025-11-08');

        // Act & Assert
        $this->expectException(\JsonException::class);
        $sut->buildPayload($scope, ['total']);
    }
}
