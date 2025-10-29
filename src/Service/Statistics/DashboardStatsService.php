<?php

namespace App\Service\Statistics;

use App\Repository\AllocationRepository;
use App\Repository\HospitalRepository;
use App\Repository\ImportRepository;
use App\Repository\UserRepository;

final readonly class DashboardStatsService
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private UserRepository $userRepository,
        private HospitalRepository $hospitalRepository,
        private ImportRepository $importRepository,
        private AllocationRepository $allocationRepository,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function getStats(): array
    {
        return [
            'users' => $this->userRepository->countAll(),
            'hospitals' => $this->hospitalRepository->countAll(),
            'imports' => $this->importRepository->countAll(),
            'allocations' => $this->allocationRepository->countAll(),
        ];
    }
}
