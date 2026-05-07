<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Adapter;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\User\Domain\Entity\User;

final readonly class DoctrineHospitalAccess implements HospitalAccessInterface
{
    public function __construct(
        private HospitalRepository $hospitalRepository,
    ) {
    }

    #[\Override]
    public function countAccessibleHospitals(User $user): int
    {
        return $this->hospitalRepository->countAccessibleHospitals($user);
    }

    #[\Override]
    public function accessibleHospitalIds(User $user): array
    {
        /** @var list<int|string> $rawIds */
        $rawIds = $this->hospitalRepository
            ->getQueryBuilderForAccessibleHospitals($user)
            ->select('h.id')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(static fn (int|string $id): int => (int) $id, $rawIds);
    }
}
