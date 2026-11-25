<?php

declare(strict_types=1);

namespace App\Research\Application\Export;

/**
 * One CSV file of an export run (ADR 045). The counts of contributing hospitals per group stay in the internal record
 * as a distribution (number of groups per hospital count); the hospital IDs themselves are never kept.
 */
final readonly class ExportFile
{
    /**
     * @param array<int, int>                  $groupsByHospitals number of groups (rows) per count of hospitals
     * @param list<array<string, scalar|null>> $belowMinimumRows  the first groups below the minimum, without IDs
     */
    public function __construct(
        public string $name,
        public string $path,
        public int $rows,
        public int $bytes,
        public string $checksum,
        public int $hospitals,
        public array $groupsByHospitals = [],
        public int $belowMinimum = 0,
        public array $belowMinimumRows = [],
    ) {
    }

    public function fileName(): string
    {
        return $this->name.'.csv';
    }

    /** @return array<string, mixed> */
    public function toRecord(): array
    {
        return [
            'file' => $this->fileName(),
            'rows' => $this->rows,
            'bytes' => $this->bytes,
            'sha256' => $this->checksum,
            'hospitals' => $this->hospitals,
            'groups_by_hospitals' => array_map(
                static fn (int $hospitals, int $groups): array => ['hospitals' => $hospitals, 'groups' => $groups],
                array_keys($this->groupsByHospitals),
                array_values($this->groupsByHospitals),
            ),
            'below_minimum' => $this->belowMinimum,
            'below_minimum_rows' => $this->belowMinimumRows,
        ];
    }
}
