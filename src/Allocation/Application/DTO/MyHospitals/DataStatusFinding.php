<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\MyHospitals;

/**
 * A hint that a hospital's data may be worth a look. Never a confirmed error.
 *
 * `amount` depends on the type: rejected rows, days without records, days since the latest allocation, months of a
 * gap, or the change in percent (always positive, see `direction`).
 */
final readonly class DataStatusFinding
{
    /**
     * @param 'up'|'down'|null $direction only for {@see DataStatusFindingType::VolumeChange}
     */
    public function __construct(
        public DataStatusFindingType $type,
        public int $amount = 0,
        public ?int $importId = null,
        public ?\DateTimeImmutable $periodStart = null,
        public ?\DateTimeImmutable $periodEnd = null,
        public ?string $direction = null,
    ) {
    }
}
