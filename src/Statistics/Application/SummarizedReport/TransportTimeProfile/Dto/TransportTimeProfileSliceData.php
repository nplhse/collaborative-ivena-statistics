<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto;

final readonly class TransportTimeProfileSliceData
{
    /**
     * @param array<string, int>                              $volumeByBucket
     * @param array<string, array<int|string, int>>           $genderByBucket
     * @param array<string, array<int|string, int>>           $urgencyByBucket
     * @param array<string, array<int|string, int>>           $physicianByBucket
     * @param array<string, array<int|string, int>>           $resusByBucket
     * @param array<string, array<int|string, int>>           $cathlabByBucket
     * @param array<string, array<int|string, int>>           $transportTypeByBucket
     * @param array<string, list<array{id: int, count: int}>> $departmentsByBucket
     * @param array<string, list<array{id: int, count: int}>> $specialitiesByBucket
     * @param array<string, list<array{id: int, count: int}>> $indicationsByBucket
     */
    public function __construct(
        public int $unknownCount,
        public array $volumeByBucket,
        public array $genderByBucket,
        public array $urgencyByBucket,
        public array $physicianByBucket,
        public array $resusByBucket,
        public array $cathlabByBucket,
        public array $transportTypeByBucket,
        public array $departmentsByBucket,
        public array $specialitiesByBucket,
        public array $indicationsByBucket,
    ) {
    }

    public static function empty(): self
    {
        return new self(0, [], [], [], [], [], [], [], [], [], []);
    }

    public function knownTotal(): int
    {
        return array_sum($this->volumeByBucket);
    }

    public function allocationTotal(): int
    {
        return $this->knownTotal() + $this->unknownCount;
    }
}
