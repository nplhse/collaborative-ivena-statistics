<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Adapter;

use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\User\Application\Activity\UserActivityBackfillImportRecord;
use App\User\Application\Contract\UserActivityBackfillImportSourceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** @psalm-suppress UnusedClass Wired via #[AsAlias] for UserActivityBackfillImportSourceInterface. */
#[AsAlias(UserActivityBackfillImportSourceInterface::class)]
final readonly class DoctrineUserActivityBackfillImportSource implements UserActivityBackfillImportSourceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function successfulImports(): array
    {
        /** @var list<array{
         *     userId: int|string|null,
         *     hospitalId: int|string,
         *     hospitalPublicId: mixed,
         *     hospitalName: string|null,
         *     createdAt: mixed
         * }> $rows
         */
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'IDENTITY(i.createdBy) AS userId',
                'IDENTITY(i.hospital) AS hospitalId',
                'h.publicId AS hospitalPublicId',
                'h.name AS hospitalName',
                'i.createdAt AS createdAt',
            )
            ->from(Import::class, 'i')
            ->innerJoin('i.hospital', 'h')
            ->andWhere('i.status IN (:statuses)')
            ->andWhere('IDENTITY(i.createdBy) IS NOT NULL')
            ->setParameter('statuses', [ImportStatus::COMPLETED, ImportStatus::PARTIAL])
            ->orderBy('i.createdAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $records = [];
        foreach ($rows as $row) {
            $userId = null !== $row['userId'] ? (int) $row['userId'] : 0;
            $createdAt = $row['createdAt'];
            if (!$createdAt instanceof \DateTimeImmutable) {
                continue;
            }

            $hospitalName = trim((string) $row['hospitalName']);
            $hospitalPublicId = $this->hospitalPublicIdToString($row['hospitalPublicId']);
            if ($userId < 1 || '' === $hospitalName || '' === $hospitalPublicId) {
                continue;
            }

            $records[] = new UserActivityBackfillImportRecord(
                userId: $userId,
                hospitalId: (int) $row['hospitalId'],
                hospitalPublicId: $hospitalPublicId,
                hospitalName: $hospitalName,
                createdAt: $createdAt,
            );
        }

        return $records;
    }

    private function hospitalPublicIdToString(mixed $publicId): string
    {
        if ($publicId instanceof Uuid) {
            return $publicId->toRfc4122();
        }

        if ($publicId instanceof \Stringable || \is_string($publicId)) {
            return (string) $publicId;
        }

        return '';
    }
}
