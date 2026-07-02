<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Application\DTO;

use App\Admin\Application\DTO\StorageMetricsDto;
use App\Admin\Application\DTO\StorageSegmentDto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageMetricsDtoTest extends TestCase
{
    public function testTotalBytesIncludesApplicationCode(): void
    {
        $dto = $this->createDto(applicationCodeBytes: 500, limitBytes: null);

        self::assertSame(1400, $dto->totalBytes());
    }

    public function testUsagePercentReturnsNullWithoutLimit(): void
    {
        $dto = $this->createDto(limitBytes: null);

        self::assertNull($dto->usagePercent());
    }

    #[DataProvider('usagePercentProvider')]
    public function testUsagePercentWithLimit(int $limit, float $expected): void
    {
        $dto = $this->createDto(limitBytes: $limit);

        self::assertSame($expected, $dto->usagePercent());
    }

    /**
     * @return iterable<string, array{int, float}>
     */
    public static function usagePercentProvider(): iterable
    {
        yield 'half used' => [1800, 50.0];
        yield 'full limit' => [900, 100.0];
    }

    public function testBarBaseBytesUsesLimitWhenConfigured(): void
    {
        $dto = $this->createDto(limitBytes: 5000);

        self::assertSame(5000, $dto->barBaseBytes());
    }

    public function testBarBaseBytesFallsBackToTotalWhenNoLimit(): void
    {
        $dto = $this->createDto(limitBytes: null);

        self::assertSame(900, $dto->barBaseBytes());
    }

    public function testFilesBytesAndGrowthHelpers(): void
    {
        $dto = $this->createDto();

        self::assertSame(500, $dto->filesBytes());
        self::assertSame(75, $dto->filesBytesLast30Days());
    }

    private function createDto(int $applicationCodeBytes = 0, ?int $limitBytes = 2000): StorageMetricsDto
    {
        return new StorageMetricsDto(
            databaseBytes: 400,
            importBytes: 300,
            mediaBytes: 200,
            applicationCodeBytes: $applicationCodeBytes,
            importBytesLast30Days: 50,
            mediaBytesLast30Days: 25,
            limitBytes: $limitBytes,
            segments: [
                new StorageSegmentDto('database', 'ops.storage.database', 400, '#000', 'fas fa-database'),
                new StorageSegmentDto('imports', 'ops.storage.imports', 300, '#000', 'fa fa-database'),
                new StorageSegmentDto('media', 'ops.storage.media', 200, '#000', 'fas fa-photo-film'),
                new StorageSegmentDto('application_code', 'ops.storage.application_code', $applicationCodeBytes, '#000', 'fas fa-code'),
            ],
        );
    }
}
