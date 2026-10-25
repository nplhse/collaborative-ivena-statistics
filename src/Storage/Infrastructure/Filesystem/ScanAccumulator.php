<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Filesystem;

use App\Storage\Application\Scan\FileScan;
use App\Storage\Application\Scan\PathClassification;
use App\Storage\Domain\Enum\MeasurementItemFlag;
use App\Storage\Domain\Enum\MeasurementItemStatus;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;

/**
 * Collects sizes per location, detail and contributor during one scan and counts every inode once.
 */
final class ScanAccumulator
{
    /** @var array<string, true> */
    private array $seenInodes = [];

    /** @var array<string, array{location: StorageLocation, detail: string|null, category: StorageCategory, contributor: string, bytes: int, status: MeasurementItemStatus, flags: array<string, MeasurementItemFlag>}> */
    private array $items = [];

    /** @var list<string> */
    private array $failures = [];

    private int $entries = 0;

    private bool $complete = true;

    /**
     * Adds the inode of the given lstat() result unless it was counted before.
     *
     * @param array<array-key, int>     $stat
     * @param list<MeasurementItemFlag> $flags
     *
     * @return bool whether the inode was new
     */
    public function add(array $stat, StorageLocation $location, ?string $detail, PathClassification $classification, array $flags = []): bool
    {
        $inode = ($stat['dev'] ?? 0).':'.($stat['ino'] ?? 0);
        if (isset($this->seenInodes[$inode])) {
            return false;
        }

        $this->seenInodes[$inode] = true;
        ++$this->entries;

        $blocks = $stat['blocks'] ?? -1;
        $bytes = $blocks >= 0 ? $blocks * 512 : ($stat['size'] ?? 0);

        $key = $this->entry($location, $detail, $classification->category, $classification->contributor);
        $this->items[$key]['bytes'] += $bytes;
        foreach ($flags as $flag) {
            $this->items[$key]['flags'][$flag->value] = $flag;
        }

        return true;
    }

    /**
     * A path below a readable location could not be read; the item it belongs to becomes incomplete.
     */
    public function failPath(string $path, string $reason, StorageLocation $location, ?string $detail, PathClassification $classification): void
    {
        $this->failures[] = sprintf('%s: %s', $path, $reason);
        $key = $this->entry($location, $detail, $classification->category, $classification->contributor);
        $this->items[$key]['flags'][MeasurementItemFlag::Incomplete->value] = MeasurementItemFlag::Incomplete;
    }

    /**
     * A whole location could not be read: its size is unknown and so is the total.
     */
    public function failLocation(string $path, string $reason, StorageLocation $location, ?string $detail, string $contributor): void
    {
        $this->failures[] = sprintf('%s: %s', $path, $reason);
        $this->complete = false;
        $key = $this->entry($location, $detail, StorageCategory::Other, $contributor);
        $this->items[$key]['status'] = MeasurementItemStatus::Failed;
    }

    public function result(): FileScan
    {
        $items = [];
        foreach ($this->items as $item) {
            $failed = MeasurementItemStatus::Failed === $item['status'];
            $items[] = new StorageMeasurementItem(
                location: $item['location'],
                category: $item['category'],
                contributor: $item['contributor'],
                bytes: $failed ? null : $item['bytes'],
                status: $item['status'],
                locationDetail: $item['detail'],
                flags: array_values($item['flags']),
            );
        }

        return new FileScan($items, $this->entries, $this->failures, $this->complete);
    }

    private function entry(StorageLocation $location, ?string $detail, StorageCategory $category, string $contributor): string
    {
        $key = implode("\0", [$location->value, $detail ?? '', $category->value, $contributor]);
        $this->items[$key] ??= [
            'location' => $location,
            'detail' => $detail,
            'category' => $category,
            'contributor' => $contributor,
            'bytes' => 0,
            'status' => MeasurementItemStatus::Measured,
            'flags' => [],
        ];

        return $key;
    }
}
