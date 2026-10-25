<?php

declare(strict_types=1);

namespace App\Tests\Support\Storage;

use App\Storage\Application\Contract\StorageMeasurementRepositoryInterface;
use App\Storage\Domain\Entity\StorageMeasurement;

final class InMemoryStorageMeasurementRepository implements StorageMeasurementRepositoryInterface
{
    /** @var array<string, StorageMeasurement> */
    private array $byDay = [];

    public function __construct(StorageMeasurement ...$measurements)
    {
        foreach ($measurements as $measurement) {
            $this->save($measurement);
        }
    }

    #[\Override]
    public function save(StorageMeasurement $measurement): StorageMeasurement
    {
        $this->byDay[$measurement->getMeasuredOn()->format('Y-m-d')] = $measurement;
        ksort($this->byDay);

        return $measurement;
    }

    #[\Override]
    public function latest(): ?StorageMeasurement
    {
        return [] === $this->byDay ? null : end($this->byDay);
    }

    #[\Override]
    public function findForDay(\DateTimeImmutable $day): ?StorageMeasurement
    {
        return $this->byDay[$day->format('Y-m-d')] ?? null;
    }

    #[\Override]
    public function findSince(\DateTimeImmutable $day): array
    {
        return array_values(array_filter(
            $this->byDay,
            static fn (string $measuredOn): bool => $measuredOn >= $day->format('Y-m-d'),
            \ARRAY_FILTER_USE_KEY,
        ));
    }
}
