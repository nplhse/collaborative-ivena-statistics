<?php

declare(strict_types=1);

namespace App\Admin\Application\DTO;

final readonly class StorageMetricsDto
{
    /**
     * @param list<StorageSegmentDto> $segments
     */
    public function __construct(
        public int $databaseBytes,
        public int $importBytes,
        public int $mediaBytes,
        public int $applicationCodeBytes,
        public int $importBytesLast30Days,
        public int $mediaBytesLast30Days,
        public ?int $limitBytes,
        public array $segments,
    ) {
    }

    public function filesBytes(): int
    {
        return $this->importBytes + $this->mediaBytes;
    }

    public function filesBytesLast30Days(): int
    {
        return $this->importBytesLast30Days + $this->mediaBytesLast30Days;
    }

    public function totalBytes(): int
    {
        return $this->databaseBytes + $this->importBytes + $this->mediaBytes + $this->applicationCodeBytes;
    }

    public function usagePercent(): ?float
    {
        if (null === $this->limitBytes || $this->limitBytes <= 0) {
            return null;
        }

        return round((float) $this->totalBytes() / (float) $this->limitBytes * 100.0, 1);
    }

    public function barBaseBytes(): int
    {
        if (null !== $this->limitBytes && $this->limitBytes > 0) {
            return $this->limitBytes;
        }

        return max(1, $this->totalBytes());
    }
}
