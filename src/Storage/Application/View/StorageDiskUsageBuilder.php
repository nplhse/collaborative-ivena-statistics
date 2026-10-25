<?php

declare(strict_types=1);

namespace App\Storage\Application\View;

use App\Storage\Application\DTO\DatabaseTableSize;
use App\Storage\Application\DTO\StorageCapacityOverview;
use App\Storage\Application\DTO\StorageCategoryRow;
use App\Storage\Application\DTO\StorageContributorRow;
use App\Storage\Application\DTO\StorageDiskUsage;
use App\Storage\Application\DTO\StorageHistory;
use App\Storage\Application\DTO\StorageLocationRow;
use App\Storage\Application\Measurement\StorageBreakdown;
use App\Storage\Application\StorageSettings;
use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\MeasurementItemFlag;
use App\Storage\Domain\Enum\MeasurementItemStatus;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;

/**
 * Builds the Disk Usage page (ADR 017, "UI") from persisted measurements only. Changes are compared with the
 * measurement seven and thirty days earlier; if that day is missing, an earlier one within a few days is used.
 */
final readonly class StorageDiskUsageBuilder
{
    /** How many days before the comparison day a measurement may be, when the day itself has none. */
    private const int COMPARISON_TOLERANCE_DAYS = 3;

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private StorageSummaryBuilder $summaryBuilder,
        private StorageSettings $settings,
    ) {
    }

    /**
     * @param list<StorageMeasurement>     $history       oldest first, including the latest measurement
     * @param list<DatabaseTableSize>|null $largestTables
     */
    public function build(StorageMeasurement $latest, array $history, ?array $largestTables): StorageDiskUsage
    {
        $summary = $this->summaryBuilder->build($latest);
        $items = $latest->getItems();
        $total = $this->measuredBytes($items);
        $reference7 = $this->reference($latest, $history, 7);
        $reference30 = $this->reference($latest, $history, 30);

        return new StorageDiskUsage(
            summary: $summary,
            categories: $this->categories($items, $total, $reference7?->getItems(), $reference30?->getItems()),
            locations: $this->locations($items, $total),
            capacity: $this->capacity($latest),
            history: $this->history($history),
            databaseBytes: $latest->getDatabaseBytes(),
            largestTables: $largestTables,
            compared7DaysOn: $reference7?->getMeasuredOn(),
            compared30DaysOn: $reference30?->getMeasuredOn(),
        );
    }

    /**
     * @param list<StorageMeasurementItem>      $items
     * @param list<StorageMeasurementItem>|null $reference7
     * @param list<StorageMeasurementItem>|null $reference30
     *
     * @return list<StorageCategoryRow>
     */
    private function categories(array $items, int $total, ?array $reference7, ?array $reference30): array
    {
        $rows = [];
        foreach (new StorageBreakdown($items)->byCategory() as $categoryTotal) {
            $category = StorageCategory::from($categoryTotal->key);
            $inCategory = static fn (StorageMeasurementItem $item): bool => $item->category === $category;
            $categoryItems = array_values(array_filter($items, $inCategory));

            $rows[] = new StorageCategoryRow(
                category: $category,
                bytes: $categoryTotal->bytes,
                sharePercent: $this->share($categoryTotal->bytes, $total) ?? 0.0,
                delta7Days: $this->delta($items, $reference7, $inCategory),
                delta30Days: $this->delta($items, $reference30, $inCategory),
                complete: $categoryTotal->complete,
                failed: $this->hasUnmeasured($categoryItems),
                flags: $categoryTotal->flags,
                contributors: $this->contributors($categoryItems, $total, $reference7, $reference30),
            );
        }

        return $rows;
    }

    /**
     * @param list<StorageMeasurementItem> $items
     *
     * @return list<StorageLocationRow>
     */
    private function locations(array $items, int $total): array
    {
        $order = array_flip(array_map(static fn (StorageLocation $location): string => $location->value, StorageLocation::cases()));
        $rows = [];

        foreach (new StorageBreakdown($items)->byLocation() as $index => $locationTotal) {
            $location = StorageLocation::from($locationTotal->key);
            $locationItems = array_values(array_filter(
                $items,
                static fn (StorageMeasurementItem $item): bool => $item->location === $location && $item->locationDetail === $locationTotal->detail,
            ));

            $rows[] = [$order[$location->value], $index, new StorageLocationRow(
                location: $location,
                detail: $locationTotal->detail,
                bytes: $locationTotal->bytes,
                sharePercent: $this->share($locationTotal->bytes, $total) ?? 0.0,
                complete: $locationTotal->complete,
                failed: $this->hasUnmeasured($locationItems),
                flags: $locationTotal->flags,
                contributors: $this->contributors($locationItems, $total, null, null),
            )];
        }

        usort($rows, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $row): StorageLocationRow => $row[2], $rows);
    }

    private function capacity(StorageMeasurement $measurement): StorageCapacityOverview
    {
        $budget = $measurement->getBudgetBytes();
        $used = $measurement->getUsedBytes();
        $filesystemTotal = $measurement->getFilesystemTotalBytes();
        $filesystemFree = $measurement->getFilesystemFreeBytes();
        $filesystemUsed = null !== $filesystemTotal && null !== $filesystemFree ? max(0, $filesystemTotal - $filesystemFree) : null;

        return new StorageCapacityOverview(
            activeSource: $measurement->getCapacitySource(),
            budgetBytes: $budget,
            budgetUsedBytes: $used,
            budgetUsedPercent: null !== $budget && null !== $used ? $this->share($used, $budget) : null,
            budgetLevel: $this->settings->thresholds->levelFor($used, $budget),
            filesystemTotalBytes: $filesystemTotal,
            filesystemFreeBytes: $filesystemFree,
            filesystemUsedBytes: $filesystemUsed,
            filesystemUsedPercent: null !== $filesystemUsed && null !== $filesystemTotal ? $this->share($filesystemUsed, $filesystemTotal) : null,
            filesystemLevel: $this->settings->thresholds->levelFor($filesystemUsed, $filesystemTotal),
            thresholds: $this->settings->thresholds,
        );
    }

    /**
     * @param list<StorageMeasurement> $history
     */
    private function history(array $history): StorageHistory
    {
        $days = [];
        $series = [];
        $capacity = [];

        foreach ($history as $measurement) {
            $days[] = $measurement->getMeasuredOn()->format('Y-m-d');
            $capacity[] = $measurement->getCapacityBytes();
            foreach (new StorageBreakdown($measurement->getItems())->byCategory() as $categoryTotal) {
                $series[$categoryTotal->key][] = $categoryTotal->bytes;
            }
        }

        return new StorageHistory($days, $series, $capacity);
    }

    /**
     * @param list<StorageMeasurement> $history
     */
    private function reference(StorageMeasurement $latest, array $history, int $days): ?StorageMeasurement
    {
        $target = $latest->getMeasuredOn()->modify(sprintf('-%d days', $days))->format('Y-m-d');
        $earliest = $latest->getMeasuredOn()->modify(sprintf('-%d days', $days + self::COMPARISON_TOLERANCE_DAYS))->format('Y-m-d');
        $found = null;

        foreach ($history as $measurement) {
            $day = $measurement->getMeasuredOn()->format('Y-m-d');
            if ($day >= $earliest && $day <= $target && (null === $found || $day > $found->getMeasuredOn()->format('Y-m-d'))) {
                $found = $measurement;
            }
        }

        return $found;
    }

    /**
     * Groups items by category and contributor, largest first.
     *
     * @param list<StorageMeasurementItem>      $items
     * @param list<StorageMeasurementItem>|null $reference7
     * @param list<StorageMeasurementItem>|null $reference30
     *
     * @return list<StorageContributorRow>
     */
    private function contributors(array $items, int $total, ?array $reference7, ?array $reference30): array
    {
        $groups = [];
        foreach ($items as $item) {
            $groups[$item->category->value."\0".$item->contributor][] = $item;
        }

        $rows = [];
        foreach ($groups as $group) {
            $category = $group[0]->category;
            $contributor = $group[0]->contributor;
            $matches = static fn (StorageMeasurementItem $item): bool => $item->category === $category && $item->contributor === $contributor;
            $measured = array_values(array_filter($group, static fn (StorageMeasurementItem $item): bool => MeasurementItemStatus::Measured === $item->status));
            $bytes = [] === $measured ? null : $this->measuredBytes($measured);

            $rows[] = new StorageContributorRow(
                contributor: $contributor,
                category: $category,
                bytes: $bytes,
                sharePercent: null === $bytes ? null : $this->share($bytes, $total),
                delta7Days: $this->delta($group, $reference7, $matches),
                delta30Days: $this->delta($group, $reference30, $matches),
                status: $this->status($group),
                flags: $this->flags($group),
            );
        }

        usort($rows, static fn (StorageContributorRow $a, StorageContributorRow $b): int => [$b->bytes ?? -1, $a->contributor] <=> [$a->bytes ?? -1, $b->contributor]);

        return $rows;
    }

    /**
     * Change of the matching items since the reference; null without a reference or when either side has
     * items without a size, because the difference would be meaningless.
     *
     * @param list<StorageMeasurementItem>           $items
     * @param list<StorageMeasurementItem>|null      $reference
     * @param callable(StorageMeasurementItem): bool $matches
     */
    private function delta(array $items, ?array $reference, callable $matches): ?int
    {
        if (null === $reference) {
            return null;
        }

        $current = array_values(array_filter($items, $matches));
        $previous = array_values(array_filter($reference, $matches));

        if ($this->hasUnmeasured($current) || $this->hasUnmeasured($previous)) {
            return null;
        }

        return $this->measuredBytes($current) - $this->measuredBytes($previous);
    }

    /**
     * @param list<StorageMeasurementItem> $items
     */
    private function measuredBytes(array $items): int
    {
        $bytes = 0;
        foreach ($items as $item) {
            $bytes += MeasurementItemStatus::Measured === $item->status ? (int) $item->bytes : 0;
        }

        return $bytes;
    }

    /**
     * @param list<StorageMeasurementItem> $items
     */
    private function hasUnmeasured(array $items): bool
    {
        return array_any($items, fn (StorageMeasurementItem $item): bool => MeasurementItemStatus::Measured !== $item->status);
    }

    /**
     * Failed wins over unavailable, which wins over measured.
     *
     * @param non-empty-list<StorageMeasurementItem> $items
     */
    private function status(array $items): MeasurementItemStatus
    {
        $statuses = array_map(static fn (StorageMeasurementItem $item): MeasurementItemStatus => $item->status, $items);

        return match (true) {
            \in_array(MeasurementItemStatus::Failed, $statuses, true) => MeasurementItemStatus::Failed,
            \in_array(MeasurementItemStatus::Unavailable, $statuses, true) => MeasurementItemStatus::Unavailable,
            default => MeasurementItemStatus::Measured,
        };
    }

    /**
     * @param list<StorageMeasurementItem> $items
     *
     * @return list<MeasurementItemFlag>
     */
    private function flags(array $items): array
    {
        $flags = [];
        foreach ($items as $item) {
            foreach ($item->flags as $flag) {
                $flags[$flag->value] = $flag;
            }
        }

        return array_values($flags);
    }

    private function share(int $bytes, int $total): ?float
    {
        return $total > 0 ? round((float) $bytes / (float) $total * 100.0, 1) : null;
    }
}
