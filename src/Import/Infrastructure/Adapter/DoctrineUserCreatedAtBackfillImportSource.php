<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Adapter;

use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\User\Application\Contract\UserCreatedAtBackfillImportSourceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** @psalm-suppress UnusedClass Wired via #[AsAlias] for UserCreatedAtBackfillImportSourceInterface. */
#[AsAlias(UserCreatedAtBackfillImportSourceInterface::class)]
final readonly class DoctrineUserCreatedAtBackfillImportSource implements UserCreatedAtBackfillImportSourceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function firstSuccessfulCreatedAtByUser(): array
    {
        /** @var list<array{userId: int|string, firstAt: \DateTimeImmutable|string|null}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(i.createdBy) AS userId', 'MIN(i.createdAt) AS firstAt')
            ->from(Import::class, 'i')
            ->andWhere('i.status IN (:statuses)')
            ->setParameter('statuses', [ImportStatus::COMPLETED, ImportStatus::PARTIAL])
            ->groupBy('i.createdBy')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $firstAt = $this->parseDateTime($row['firstAt']);
            if (!$firstAt instanceof \DateTimeImmutable) {
                continue;
            }

            $result[(int) $row['userId']] = $firstAt;
        }

        return $result;
    }

    #[\Override]
    public function firstSuccessfulCreatedAtByHospital(): array
    {
        /** @var list<array{hospitalId: int|string, firstAt: \DateTimeImmutable|string|null}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(i.hospital) AS hospitalId', 'MIN(i.createdAt) AS firstAt')
            ->from(Import::class, 'i')
            ->andWhere('i.status IN (:statuses)')
            ->setParameter('statuses', [ImportStatus::COMPLETED, ImportStatus::PARTIAL])
            ->groupBy('i.hospital')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $firstAt = $this->parseDateTime($row['firstAt']);
            if (!$firstAt instanceof \DateTimeImmutable) {
                continue;
            }

            $result[(int) $row['hospitalId']] = $firstAt;
        }

        return $result;
    }

    private function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (\is_string($value) && '' !== $value) {
            return new \DateTimeImmutable($value);
        }

        return null;
    }
}
