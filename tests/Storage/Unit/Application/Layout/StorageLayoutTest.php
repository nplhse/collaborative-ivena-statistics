<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\Layout;

use App\Storage\Application\Layout\ReleaseDirectory;
use App\Storage\Application\Layout\SharedLink;
use App\Storage\Application\Layout\StorageLayout;
use App\Storage\Application\Scan\FileScan;
use App\Storage\Domain\Enum\MeasurementItemStatus;
use App\Storage\Domain\Enum\MeasurementMode;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;
use PHPUnit\Framework\TestCase;

final class StorageLayoutTest extends TestCase
{
    public function testDeployerLayoutListsSharedPathsOnBothSides(): void
    {
        $layout = StorageLayout::deployer(
            '/deploy',
            new ReleaseDirectory('11', '/deploy/releases/11'),
            [new ReleaseDirectory('10', '/deploy/releases/10')],
            '/deploy/shared',
            [new SharedLink('public/media', 'uploads/media')],
        );

        self::assertSame(MeasurementMode::Deployer, $layout->mode);
        self::assertSame(['public/media'], $layout->sharedReleasePaths());
        self::assertSame(['uploads/media'], $layout->linkedSharedPaths());
    }

    public function testScanTotalCountsMeasuredItemsOnly(): void
    {
        $items = [
            new StorageMeasurementItem(StorageLocation::Project, StorageCategory::Application, 'vendor', 300),
            new StorageMeasurementItem(StorageLocation::Project, StorageCategory::Temporary, 'var_cache', null, MeasurementItemStatus::Unavailable),
        ];

        self::assertSame(300, new FileScan($items, 2, [], true)->totalBytes());
        self::assertNull(new FileScan($items, 2, [], false)->totalBytes());
    }
}
