<?php

declare(strict_types=1);

namespace App\Storage\Domain\Entity;

use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Storage\Domain\Enum\MeasurementMode;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One storage measurement per day (ADR 017, "Persistence"). A second run on the same day replaces the values
 * of the existing row. Sizes that could not be measured are null, never 0.
 */
#[ORM\Entity]
#[ORM\Table(name: 'storage_measurement')]
#[ORM\UniqueConstraint(name: 'uniq_storage_measurement_measured_on', columns: ['measured_on'])]
class StorageMeasurement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $measuredOn;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $measuredAt;

    #[ORM\Column]
    private int $durationMs;

    #[ORM\Column(length: 16, enumType: MeasurementMode::class)]
    private MeasurementMode $mode;

    /** Files + database; null when the file walk failed. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $usedBytes;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $databaseBytes;

    #[ORM\Column(length: 16, enumType: CapacitySource::class)]
    private CapacitySource $capacitySource;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $capacityBytes;

    /** Configured budget at the time of the measurement, whatever the reference was. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $budgetBytes;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $filesystemTotalBytes;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $filesystemFreeBytes;

    #[ORM\Column(length: 16, enumType: CapacityLevel::class)]
    private CapacityLevel $level;

    /** @var list<array<array-key, mixed>> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $items;

    /**
     * @param list<StorageMeasurementItem> $items
     */
    public function __construct(
        \DateTimeImmutable $measuredOn,
        \DateTimeImmutable $measuredAt,
        int $durationMs,
        MeasurementMode $mode,
        ?int $usedBytes,
        ?int $databaseBytes,
        CapacitySource $capacitySource,
        ?int $capacityBytes,
        ?int $budgetBytes,
        ?int $filesystemTotalBytes,
        ?int $filesystemFreeBytes,
        CapacityLevel $level,
        array $items,
    ) {
        $this->assertNonNegative([
            'duration' => $durationMs,
            'used' => $usedBytes,
            'database' => $databaseBytes,
            'budget' => $budgetBytes,
            'filesystem total' => $filesystemTotalBytes,
            'filesystem free' => $filesystemFreeBytes,
        ]);

        if ((CapacitySource::None === $capacitySource) !== (null === $capacityBytes)) {
            throw new \InvalidArgumentException('A capacity reference needs a capacity, and only a reference may have one.');
        }

        if (null !== $capacityBytes && $capacityBytes <= 0) {
            throw new \InvalidArgumentException('The storage capacity must be greater than 0.');
        }

        if (null === $usedBytes && $level->isKnown()) {
            throw new \InvalidArgumentException('Without a measured usage the capacity level must be unknown.');
        }

        $this->measuredOn = $measuredOn->setTime(0, 0);
        $this->measuredAt = $measuredAt;
        $this->durationMs = $durationMs;
        $this->mode = $mode;
        $this->usedBytes = $usedBytes;
        $this->databaseBytes = $databaseBytes;
        $this->capacitySource = $capacitySource;
        $this->capacityBytes = $capacityBytes;
        $this->budgetBytes = $budgetBytes;
        $this->filesystemTotalBytes = $filesystemTotalBytes;
        $this->filesystemFreeBytes = $filesystemFreeBytes;
        $this->level = $level;
        $this->items = array_map(static fn (StorageMeasurementItem $item): array => $item->toArray(), $items);
    }

    /**
     * Takes over the values of a newer measurement of the same day.
     */
    public function replaceWith(self $newer): void
    {
        if ($newer->measuredOn->format('Y-m-d') !== $this->measuredOn->format('Y-m-d')) {
            throw new \InvalidArgumentException('Only a measurement of the same day can replace another one.');
        }

        $this->measuredAt = $newer->measuredAt;
        $this->durationMs = $newer->durationMs;
        $this->mode = $newer->mode;
        $this->usedBytes = $newer->usedBytes;
        $this->databaseBytes = $newer->databaseBytes;
        $this->capacitySource = $newer->capacitySource;
        $this->capacityBytes = $newer->capacityBytes;
        $this->budgetBytes = $newer->budgetBytes;
        $this->filesystemTotalBytes = $newer->filesystemTotalBytes;
        $this->filesystemFreeBytes = $newer->filesystemFreeBytes;
        $this->level = $newer->level;
        $this->items = $newer->items;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMeasuredOn(): \DateTimeImmutable
    {
        return $this->measuredOn;
    }

    public function getMeasuredAt(): \DateTimeImmutable
    {
        return $this->measuredAt;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function getMode(): MeasurementMode
    {
        return $this->mode;
    }

    public function getUsedBytes(): ?int
    {
        return $this->usedBytes;
    }

    public function getDatabaseBytes(): ?int
    {
        return $this->databaseBytes;
    }

    public function getCapacitySource(): CapacitySource
    {
        return $this->capacitySource;
    }

    public function getCapacityBytes(): ?int
    {
        return $this->capacityBytes;
    }

    public function getBudgetBytes(): ?int
    {
        return $this->budgetBytes;
    }

    public function getFilesystemTotalBytes(): ?int
    {
        return $this->filesystemTotalBytes;
    }

    public function getFilesystemFreeBytes(): ?int
    {
        return $this->filesystemFreeBytes;
    }

    public function getLevel(): CapacityLevel
    {
        return $this->level;
    }

    /**
     * @return list<StorageMeasurementItem>
     */
    public function getItems(): array
    {
        return array_map(StorageMeasurementItem::fromArray(...), $this->items);
    }

    /**
     * @param array<string, int|null> $values
     */
    private function assertNonNegative(array $values): void
    {
        foreach ($values as $name => $value) {
            if (null !== $value && $value < 0) {
                throw new \InvalidArgumentException(sprintf('The storage %s value cannot be negative.', $name));
            }
        }
    }
}
