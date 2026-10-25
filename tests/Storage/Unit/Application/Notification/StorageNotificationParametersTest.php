<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\Notification;

use App\Storage\Application\Notification\CapacityLevelChange;
use App\Storage\Application\Notification\StorageNotificationParameters;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Storage\Domain\Enum\MeasurementItemStatus;
use App\Storage\Domain\Enum\MeasurementMode;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;
use App\Tests\Support\Storage\StorageMeasurementBuilder;
use PHPUnit\Framework\TestCase;

final class StorageNotificationParametersTest extends TestCase
{
    private const int MB = 1024 * 1024;

    public function testAlertNamesTheChangeAndTheRemainingCapacity(): void
    {
        $measurement = StorageMeasurementBuilder::create(usedBytes: 950 * self::MB, level: CapacityLevel::Warning, capacityBytes: 1000 * self::MB);
        $change = CapacityLevelChange::between(CapacityLevel::Notice, CapacityLevel::Warning);
        self::assertNotNull($change);

        self::assertSame([
            'direction' => 'escalated',
            'from' => 'notice',
            'to' => 'warning',
            'measuredOn' => '2026-10-25',
            'capacitySource' => 'budget',
            'reference' => 'budget',
            'capacity' => '1000.0 MB',
            'comparedUsed' => '950.0 MB',
            'usedPercent' => 95.0,
            'remaining' => '50.0 MB',
            'remainingPercent' => 5.0,
        ], StorageNotificationParameters::alert($measurement, $change));
    }

    public function testAlertOnTheFilesystemComparesItsWholeUsage(): void
    {
        $measurement = StorageMeasurementBuilder::create(
            usedBytes: 10 * self::MB,
            level: CapacityLevel::Critical,
            source: CapacitySource::Filesystem,
            capacityBytes: 100 * self::MB,
            filesystemTotalBytes: 100 * self::MB,
            filesystemFreeBytes: self::MB / 2,
        );
        $change = CapacityLevelChange::between(CapacityLevel::Exceeded, CapacityLevel::Critical);
        self::assertNotNull($change);

        $parameters = StorageNotificationParameters::alert($measurement, $change);

        self::assertSame('deescalated', $parameters['direction']);
        self::assertSame('filesystem', $parameters['reference']);
        self::assertSame('99.5 MB', $parameters['comparedUsed']);
        self::assertSame(99.5, $parameters['usedPercent']);
        self::assertSame('512.0 KB', $parameters['remaining']);
    }

    public function testReportContainsUsageReferenceChangeAndLargestCategories(): void
    {
        $previous = StorageMeasurementBuilder::create(day: '2026-10-24', usedBytes: 600 * self::MB);
        $measurement = StorageMeasurementBuilder::create(usedBytes: 640 * self::MB, level: CapacityLevel::Normal, capacityBytes: 1000 * self::MB, databaseBytes: 300 * self::MB, items: [
            new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', 300 * self::MB),
            new StorageMeasurementItem(StorageLocation::Project, StorageCategory::Application, 'vendor', 200 * self::MB),
            new StorageMeasurementItem(StorageLocation::Project, StorageCategory::Imports, 'var_imports', 100 * self::MB),
            new StorageMeasurementItem(StorageLocation::Project, StorageCategory::Media, 'media', 40 * self::MB),
        ]);

        self::assertSame([
            'measuredOn' => '2026-10-25',
            'level' => 'normal',
            'usage' => 'measured',
            'used' => '640.0 MB',
            'database' => '300.0 MB',
            'capacitySource' => 'budget',
            'reference' => 'budget',
            'capacity' => '1000.0 MB',
            'comparedUsed' => '640.0 MB',
            'usedPercent' => 64.0,
            'remaining' => '360.0 MB',
            'remainingPercent' => 36.0,
            'changeDirection' => 'up',
            'change' => '40.0 MB',
            'daysSincePrevious' => 1,
            'category1' => 'database',
            'category1Size' => '300.0 MB',
            'category2' => 'application',
            'category2Size' => '200.0 MB',
            'category3' => 'imports',
            'category3Size' => '100.0 MB',
            'mode' => 'standalone',
            'previousReleases' => null,
            'previousReleasesSize' => null,
            'shared' => null,
        ], StorageNotificationParameters::report($measurement, $previous));
    }

    public function testReportInDeployerModeAddsPreviousReleasesAndSharedTotals(): void
    {
        $measurement = StorageMeasurementBuilder::create(usedBytes: 900 * self::MB, mode: MeasurementMode::Deployer, items: [
            new StorageMeasurementItem(StorageLocation::Shared, StorageCategory::Imports, 'var_imports', 300 * self::MB, locationDetail: 'var/imports'),
            new StorageMeasurementItem(StorageLocation::Shared, StorageCategory::Media, 'media', 100 * self::MB, locationDetail: 'public/uploads/media'),
            new StorageMeasurementItem(StorageLocation::CurrentRelease, StorageCategory::Application, 'vendor', 200 * self::MB, locationDetail: '20261025.1'),
            new StorageMeasurementItem(StorageLocation::PreviousRelease, StorageCategory::Application, 'vendor', 150 * self::MB, locationDetail: '20261024.1'),
            new StorageMeasurementItem(StorageLocation::PreviousRelease, StorageCategory::Temporary, 'var_cache', 50 * self::MB, locationDetail: '20261024.1'),
            new StorageMeasurementItem(StorageLocation::PreviousRelease, StorageCategory::Application, 'vendor', 100 * self::MB, locationDetail: '20261023.1'),
        ]);

        $parameters = StorageNotificationParameters::report($measurement, null);

        self::assertSame('deployer', $parameters['mode']);
        self::assertSame(2, $parameters['previousReleases']);
        self::assertSame('300.0 MB', $parameters['previousReleasesSize']);
        self::assertSame('400.0 MB', $parameters['shared']);
        self::assertSame('application', $parameters['category1']);
        self::assertSame('450.0 MB', $parameters['category1Size']);
    }

    public function testReportWithoutPreviousMeasurementHasNoChange(): void
    {
        $parameters = StorageNotificationParameters::report(StorageMeasurementBuilder::create(), null);

        self::assertSame('unknown', $parameters['changeDirection']);
        self::assertNull($parameters['change']);
        self::assertNull($parameters['daysSincePrevious']);
    }

    public function testReportComparesWithAnOlderMeasurementAndShowsDecreasesAndStandstill(): void
    {
        $measurement = StorageMeasurementBuilder::create(usedBytes: 500);

        $lower = StorageNotificationParameters::report($measurement, StorageMeasurementBuilder::create(day: '2026-10-21', usedBytes: 800));
        self::assertSame('down', $lower['changeDirection']);
        self::assertSame('300 B', $lower['change']);
        self::assertSame(4, $lower['daysSincePrevious']);

        $same = StorageNotificationParameters::report($measurement, StorageMeasurementBuilder::create(day: '2026-10-24', usedBytes: 500));
        self::assertSame('none', $same['changeDirection']);

        $failedBefore = StorageNotificationParameters::report($measurement, StorageMeasurementBuilder::create(day: '2026-10-24', usedBytes: null, level: CapacityLevel::Unknown));
        self::assertSame('unknown', $failedBefore['changeDirection']);
    }

    public function testReportOfAnIncompleteMeasurementSaysSo(): void
    {
        $measurement = StorageMeasurementBuilder::create(usedBytes: null, level: CapacityLevel::Unknown, databaseBytes: null, items: [
            new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', null, MeasurementItemStatus::Failed),
        ]);

        $parameters = StorageNotificationParameters::report($measurement, StorageMeasurementBuilder::create(day: '2026-10-24'));

        self::assertSame('unavailable', $parameters['usage']);
        self::assertNull($parameters['used']);
        self::assertNull($parameters['database']);
        self::assertSame('unavailable', $parameters['reference'], 'there is a budget, but nothing to compare with it');
        self::assertSame('1000 B', $parameters['capacity']);
        self::assertNull($parameters['remaining']);
        self::assertSame('unknown', $parameters['changeDirection']);
        self::assertSame('unknown', $parameters['level']);
    }

    public function testReportWithoutReference(): void
    {
        $parameters = StorageNotificationParameters::report(
            StorageMeasurementBuilder::create(level: CapacityLevel::Unknown, source: CapacitySource::None, capacityBytes: null),
            null,
        );

        self::assertSame('none', $parameters['capacitySource']);
        self::assertSame('none', $parameters['reference']);
        self::assertNull($parameters['capacity']);
        self::assertNull($parameters['usedPercent']);
    }
}
