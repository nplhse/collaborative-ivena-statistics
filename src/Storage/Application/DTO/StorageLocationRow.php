<?php

declare(strict_types=1);

namespace App\Storage\Application\DTO;

use App\Storage\Domain\Enum\MeasurementItemFlag;
use App\Storage\Domain\Enum\StorageLocation;

/**
 * One row of the location table: the current release, each previous release, the shared directory,
 * the rest of the deploy root, the project directory or the database.
 */
final readonly class StorageLocationRow
{
    /**
     * @param string|null                 $detail       release name, if the location has several
     * @param list<MeasurementItemFlag>   $flags
     * @param list<StorageContributorRow> $contributors largest first
     */
    public function __construct(
        public StorageLocation $location,
        public ?string $detail,
        public int $bytes,
        public float $sharePercent,
        public bool $complete,
        public bool $failed,
        public array $flags,
        public array $contributors,
    ) {
    }
}
