<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\View;

use App\Storage\Application\DTO\DatabaseTableSize;
use App\Storage\Application\DTO\StorageCategoryRow;
use App\Storage\Application\DTO\StorageContributorRow;
use App\Storage\Application\DTO\StorageLocationRow;
use App\Storage\Application\View\StorageDiskUsageBuilder;
use App\Storage\Application\View\StorageSummaryBuilder;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Storage\Domain\Enum\MeasurementItemFlag;
use App\Storage\Domain\Enum\MeasurementItemStatus;
use App\Storage\Domain\Enum\MeasurementMode;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class StorageDiskUsageBuilderTest extends TestCase
{
    public function testBuildsCategoriesWithContributorsSharesAndChanges(): void
    {
        $latest = StorageViewFixtures::measurement('2026-10-25', [
            new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', 400),
            new StorageMeasurementItem(StorageLocation::Shared, StorageCategory::Imports, 'var_imports', 300),
            new StorageMeasurementItem(StorageLocation::CurrentRelease, StorageCategory::Application, 'vendor', 150, locationDetail: '12'),
            new StorageMeasurementItem(StorageLocation::PreviousRelease, StorageCategory::Application, 'vendor', 100, locationDetail: '11'),
            new StorageMeasurementItem(StorageLocation::CurrentRelease, StorageCategory::Application, 'source', 50, locationDetail: '12'),
        ]);
        $weekAgo = StorageViewFixtures::measurement('2026-10-18', [
            new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', 350),
            new StorageMeasurementItem(StorageLocation::Shared, StorageCategory::Imports, 'var_imports', 300),
            new StorageMeasurementItem(StorageLocation::CurrentRelease, StorageCategory::Application, 'vendor', 150, locationDetail: '11'),
        ]);
        $monthAgo = StorageViewFixtures::measurement('2026-09-23', [
            new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', 100),
        ]);

        $usage = $this->builder()->build($latest, [$monthAgo, $weekAgo, $latest], null);

        self::assertSame('2026-10-18', $usage->compared7DaysOn?->format('Y-m-d'));
        self::assertSame('2026-09-23', $usage->compared30DaysOn?->format('Y-m-d'), 'two days before the comparison day is within the tolerance');

        $categories = $this->byCategory($usage->categories);
        self::assertSame(array_map(static fn (StorageCategory $category): string => $category->value, StorageCategory::cases()), array_keys($categories));

        $database = $categories['database'];
        self::assertSame(400, $database->bytes);
        self::assertSame(40.0, $database->sharePercent);
        self::assertSame(50, $database->delta7Days);
        self::assertSame(300, $database->delta30Days);
        self::assertTrue($database->complete);
        self::assertFalse($database->failed);

        $application = $categories['application'];
        self::assertSame(300, $application->bytes);
        self::assertSame(150, $application->delta7Days);
        self::assertSame(300, $application->delta30Days);
        self::assertSame(['vendor', 'source'], array_map(static fn (StorageContributorRow $row): string => $row->contributor, $application->contributors));
        $vendor = $application->contributors[0];
        self::assertSame(250, $vendor->bytes);
        self::assertSame(25.0, $vendor->sharePercent);
        self::assertSame(100, $vendor->delta7Days);
        self::assertSame(MeasurementItemStatus::Measured, $vendor->status);
        self::assertSame('storage.contributor.vendor', $vendor->labelKey());
        self::assertSame(50, $application->contributors[1]->delta7Days, 'a new contributor grows from nothing');

        self::assertSame(0, $categories['media']->bytes);
        self::assertSame([], $categories['media']->contributors);
        self::assertSame(0, $categories['media']->delta7Days);
    }

    public function testChangesAreUnknownWithoutAComparisonOrWithFailedItems(): void
    {
        $latest = StorageViewFixtures::measurement('2026-10-25', [
            new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', null, MeasurementItemStatus::Failed),
            new StorageMeasurementItem(StorageLocation::Shared, StorageCategory::Imports, 'var_imports', 300, flags: [MeasurementItemFlag::Incomplete]),
            new StorageMeasurementItem(StorageLocation::Shared, StorageCategory::Other, 'shared_other', null, MeasurementItemStatus::Unavailable),
        ], used: null, level: CapacityLevel::Unknown);
        $tooOld = StorageViewFixtures::measurement('2026-10-14', [
            new StorageMeasurementItem(StorageLocation::Shared, StorageCategory::Imports, 'var_imports', 100),
        ]);
        $weekAgo = StorageViewFixtures::measurement('2026-10-17', [
            new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', 100),
            new StorageMeasurementItem(StorageLocation::Shared, StorageCategory::Imports, 'var_imports', 200),
        ]);

        $usage = $this->builder()->build($latest, [$tooOld, $weekAgo, $latest], []);
        $categories = $this->byCategory($usage->categories);

        self::assertSame('2026-10-17', $usage->compared7DaysOn?->format('Y-m-d'));
        self::assertNull($usage->compared30DaysOn);

        self::assertTrue($categories['database']->failed);
        self::assertFalse($categories['database']->complete);
        self::assertNull($categories['database']->delta7Days);
        self::assertNull($categories['database']->contributors[0]->bytes);
        self::assertNull($categories['database']->contributors[0]->sharePercent);
        self::assertSame(MeasurementItemStatus::Failed, $categories['database']->contributors[0]->status);

        self::assertFalse($categories['imports']->failed);
        self::assertFalse($categories['imports']->complete);
        self::assertSame([MeasurementItemFlag::Incomplete], $categories['imports']->flags);
        self::assertSame(100, $categories['imports']->delta7Days);
        self::assertNull($categories['imports']->delta30Days);
        self::assertSame(100.0, $categories['imports']->sharePercent);

        self::assertSame(MeasurementItemStatus::Unavailable, $categories['other']->contributors[0]->status);
        self::assertSame([], $usage->largestTables);
    }

    public function testOrdersLocationsFromCurrentReleaseToDatabase(): void
    {
        $latest = StorageViewFixtures::measurement('2026-10-25', [
            new StorageMeasurementItem(StorageLocation::Shared, StorageCategory::Media, 'media_uploads', 200),
            new StorageMeasurementItem(StorageLocation::Shared, StorageCategory::Other, 'geodata', 100),
            new StorageMeasurementItem(StorageLocation::CurrentRelease, StorageCategory::Application, 'vendor', 300, locationDetail: '12'),
            new StorageMeasurementItem(StorageLocation::PreviousRelease, StorageCategory::Application, 'vendor', 250, locationDetail: '11'),
            new StorageMeasurementItem(StorageLocation::PreviousRelease, StorageCategory::Media, 'media_uploads', 50, locationDetail: '11', flags: [MeasurementItemFlag::LeftoverInRelease]),
            new StorageMeasurementItem(StorageLocation::PreviousRelease, StorageCategory::Application, 'vendor', 0, locationDetail: '10'),
            new StorageMeasurementItem(StorageLocation::DeployRoot, StorageCategory::Other, 'deploy_metadata', 10),
            new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', 90),
        ]);

        $usage = $this->builder()->build($latest, [$latest], null);

        self::assertTrue($usage->isDeployer());
        self::assertSame(
            ['current_release:12', 'previous_release:11', 'previous_release:10', 'shared:', 'deploy_root:', 'database:'],
            array_map(static fn (StorageLocationRow $row): string => $row->location->value.':'.$row->detail, $usage->locations),
        );

        $previous = $usage->locations[1];
        self::assertSame(300, $previous->bytes);
        self::assertSame(30.0, $previous->sharePercent);
        self::assertSame([MeasurementItemFlag::LeftoverInRelease], $previous->flags);
        self::assertSame(['vendor', 'media_uploads'], array_map(static fn (StorageContributorRow $row): string => $row->contributor, $previous->contributors));
        self::assertNull($previous->contributors[0]->delta7Days, 'locations have no changes, release names differ from day to day');
        self::assertSame(StorageCategory::Media, $previous->contributors[1]->category);

        $shared = $usage->locations[3];
        self::assertSame(300, $shared->bytes);
        self::assertTrue($shared->complete);
        self::assertFalse($shared->failed);
    }

    public function testKeepsBudgetAndFilesystemApart(): void
    {
        $latest = StorageViewFixtures::measurement(
            '2026-10-25',
            [new StorageMeasurementItem(StorageLocation::Project, StorageCategory::Application, 'source', 850)],
            budget: 1000,
            filesystemTotal: 10_000,
            filesystemFree: 500,
            level: CapacityLevel::Notice,
            mode: MeasurementMode::Standalone,
        );

        $usage = $this->builder()->build($latest, [$latest], null);
        $capacity = $usage->capacity;

        self::assertFalse($usage->isDeployer());
        self::assertSame(CapacitySource::Budget, $capacity->activeSource);
        self::assertTrue($capacity->hasBudget());
        self::assertSame(1000, $capacity->budgetBytes);
        self::assertSame(850, $capacity->budgetUsedBytes);
        self::assertSame(85.0, $capacity->budgetUsedPercent);
        self::assertSame(CapacityLevel::Notice, $capacity->budgetLevel);
        self::assertSame('info', $capacity->budgetStyle());
        self::assertTrue($capacity->hasFilesystem());
        self::assertSame(9500, $capacity->filesystemUsedBytes);
        self::assertSame(95.0, $capacity->filesystemUsedPercent);
        self::assertSame(CapacityLevel::Warning, $capacity->filesystemLevel);
        self::assertSame('warning', $capacity->filesystemStyle());
        self::assertSame(80, $capacity->thresholds->noticePercent);
    }

    public function testWithoutBudgetOrFilesystemTheCapacityIsEmpty(): void
    {
        $latest = StorageViewFixtures::measurement(source: CapacitySource::None, budget: null, filesystemTotal: null, filesystemFree: null, level: CapacityLevel::Unknown);

        $capacity = $this->builder()->build($latest, [$latest], null)->capacity;

        self::assertFalse($capacity->hasBudget());
        self::assertNull($capacity->budgetUsedPercent);
        self::assertSame(CapacityLevel::Unknown, $capacity->budgetLevel);
        self::assertFalse($capacity->hasFilesystem());
        self::assertNull($capacity->filesystemUsedBytes);
        self::assertNull($capacity->filesystemUsedPercent);
        self::assertSame('secondary', $capacity->filesystemStyle());
    }

    public function testBuildsTheHistoryPerCategory(): void
    {
        $older = StorageViewFixtures::measurement('2026-10-24', [
            new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', 100),
        ], capacity: 800, budget: 800);
        $latest = StorageViewFixtures::measurement('2026-10-25', [
            new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', 120),
            new StorageMeasurementItem(StorageLocation::Shared, StorageCategory::Media, 'media_uploads', 30),
        ]);

        $history = $this->builder()->build($latest, [$older, $latest], null)->history;

        self::assertFalse($history->isEmpty());
        self::assertSame(['2026-10-24', '2026-10-25'], $history->days);
        self::assertSame([800, 1000], $history->capacity);

        $chart = $history->toChartValue();
        self::assertSame(['2026-10-24', '2026-10-25'], $chart['days']);
        self::assertCount(6, $chart['series']);
        self::assertSame(['key' => 'database', 'color' => StorageCategory::Database->color(), 'data' => [100, 120]], $chart['series'][0]);
        self::assertSame([0, 30], $chart['series'][2]['data']);
        self::assertSame([800, 1000], $chart['capacity']);
    }

    public function testComputesTheShareOfEachTableInTheDatabase(): void
    {
        $latest = StorageViewFixtures::measurement(database: 2000);
        $table = new DatabaseTableSize('public', 'import', 500);

        $usage = $this->builder()->build($latest, [$latest], [$table, new DatabaseTableSize('audit', 'entry', 100)]);

        self::assertSame(25.0, $usage->tableSharePercent($table));
        self::assertSame('import', $table->name());
        self::assertSame('audit.entry', ($usage->largestTables ?? [])[1]->name());
        self::assertSame(2000, $usage->databaseBytes);
        self::assertNull($this->builder()->build(StorageViewFixtures::measurement(), [], [])->tableSharePercent($table));
        self::assertTrue($this->builder()->build(StorageViewFixtures::measurement(), [], [])->history->isEmpty());
    }

    /**
     * @param list<StorageCategoryRow> $rows
     *
     * @return array<string, StorageCategoryRow>
     */
    private function byCategory(array $rows): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row->category->value] = $row;
        }

        return $byKey;
    }

    private function builder(): StorageDiskUsageBuilder
    {
        $settings = StorageViewFixtures::settings();

        return new StorageDiskUsageBuilder(
            new StorageSummaryBuilder($settings, new MockClock(new \DateTimeImmutable('2026-10-25 12:00'))),
            $settings,
        );
    }
}
