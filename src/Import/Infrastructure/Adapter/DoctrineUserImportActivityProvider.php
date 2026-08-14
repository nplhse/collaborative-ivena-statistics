<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Adapter;

use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\User\Application\Contract\UserImportActivityProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** @psalm-suppress UnusedClass Wired via #[AsAlias] for UserImportActivityProviderInterface. */
#[AsAlias(UserImportActivityProviderInterface::class)]
final readonly class DoctrineUserImportActivityProvider implements UserImportActivityProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function countsByUserIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));
        if ([] === $userIds) {
            return [];
        }

        /** @var list<array{userId: int|string, cnt: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(i.createdBy) AS userId', 'COUNT(i.id) AS cnt')
            ->from(Import::class, 'i')
            ->andWhere('IDENTITY(i.createdBy) IN (:userIds)')
            ->andWhere('i.status IN (:statuses)')
            ->setParameter('userIds', $userIds)
            ->setParameter('statuses', [ImportStatus::COMPLETED, ImportStatus::PARTIAL])
            ->groupBy('i.createdBy')
            ->getQuery()
            ->getArrayResult();

        $counts = array_fill_keys($userIds, 0);
        foreach ($rows as $row) {
            $counts[(int) $row['userId']] = (int) $row['cnt'];
        }

        return $counts;
    }
}
