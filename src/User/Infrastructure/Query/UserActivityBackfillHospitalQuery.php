<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Query;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UserActivityBackfillHospitalQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<array{
     *     userId: int,
     *     hospitalId: int,
     *     grantId: int,
     *     hospitalPublicId: string,
     *     hospitalName: string,
     *     createdAt: \DateTimeImmutable
     * }>
     */
    public function accessGrants(): array
    {
        /** @var list<array{
         *     userId: int|string,
         *     hospitalId: int|string,
         *     grantId: int|string,
         *     hospitalPublicId: mixed,
         *     hospitalName: string|null,
         *     createdAt: \DateTimeImmutable|string
         * }> $rows
         */
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'IDENTITY(g.user) AS userId',
                'IDENTITY(g.hospital) AS hospitalId',
                'g.id AS grantId',
                'h.publicId AS hospitalPublicId',
                'h.name AS hospitalName',
                'g.createdAt AS createdAt',
            )
            ->from(HospitalAccessGrant::class, 'g')
            ->innerJoin('g.hospital', 'h')
            ->orderBy('g.createdAt', 'ASC')
            ->addOrderBy('g.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $grants = [];
        foreach ($rows as $row) {
            $createdAt = $row['createdAt'];
            if (!$createdAt instanceof \DateTimeImmutable) {
                continue;
            }

            $hospitalName = trim((string) $row['hospitalName']);
            $hospitalPublicId = $this->stringifyPublicId($row['hospitalPublicId']);
            if ('' === $hospitalName || '' === $hospitalPublicId) {
                continue;
            }

            $grants[] = [
                'userId' => (int) $row['userId'],
                'hospitalId' => (int) $row['hospitalId'],
                'grantId' => (int) $row['grantId'],
                'hospitalPublicId' => $hospitalPublicId,
                'hospitalName' => $hospitalName,
                'createdAt' => $createdAt,
            ];
        }

        return $grants;
    }

    /**
     * @return array<int, int> hospitalId => owner userId
     */
    public function ownerUserIdsByHospital(): array
    {
        /** @var list<array{hospitalId: int|string, ownerId: int|string|null}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('h.id AS hospitalId', 'IDENTITY(h.owner) AS ownerId')
            ->from(Hospital::class, 'h')
            ->andWhere('IDENTITY(h.owner) IS NOT NULL')
            ->getQuery()
            ->getArrayResult();

        $owners = [];
        foreach ($rows as $row) {
            if (null === $row['ownerId']) {
                continue;
            }

            $owners[(int) $row['hospitalId']] = (int) $row['ownerId'];
        }

        return $owners;
    }

    private function stringifyPublicId(mixed $publicId): string
    {
        if ($publicId instanceof \Stringable || \is_string($publicId)) {
            return (string) $publicId;
        }

        return '';
    }
}
