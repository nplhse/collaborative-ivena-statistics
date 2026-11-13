<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Query;

use App\Import\Application\Contracts\ImportTypeExtensionInterface;
use App\Import\Application\Service\ImportListAccess;
use App\Import\Application\Service\ImportTypeExtensionRegistry;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportBatchRunItem;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\UI\Http\DTO\ListImportQueryParametersDTO;
use App\Shared\Infrastructure\Doctrine\SortDirections;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class ListImportsQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ImportListAccess $importListAccess,
        private ImportTypeExtensionRegistry $typeExtensions,
    ) {
    }

    public function listQuery(User $user, ListImportQueryParametersDTO $query): QueryBuilder
    {
        $accessibleHospitalIds = $this->importListAccess->resolveAccessibleHospitalIds($user);

        $qb = $this->entityManager->createQueryBuilder()
            ->select('i', 'h', 'owner', 'createdBy', 'updatedBy')
            ->addSelect('(CASE WHEN i.updatedAt IS NOT NULL THEN i.updatedAt ELSE i.createdAt END) AS HIDDEN sortDate')
            ->from(Import::class, 'i')
            ->innerJoin('i.hospital', 'h')
            ->leftJoin('h.owner', 'owner')
            ->leftJoin('i.createdBy', 'createdBy')
            ->leftJoin('i.updatedBy', 'updatedBy');

        // Types of other contexts (`import.type_extension`) are visible on the hospitals the extension
        // names, for example encounter imports with the encounter grants (ADR 028); all other types on
        // the hospitals the user can access.
        $extensions = $this->typeExtensions->all();
        $visibility = $qb->expr()->orX();
        if ([] !== $accessibleHospitalIds) {
            $regular = $qb->expr()->andX('h.id IN (:accessibleHospitalIds)');
            $qb->setParameter('accessibleHospitalIds', $accessibleHospitalIds);
            if ([] !== $extensions) {
                $regular->add('i.type NOT IN (:extensionTypes)');
                $qb->setParameter('extensionTypes', array_map(static fn (ImportTypeExtensionInterface $extension): ImportType => $extension::importType(), $extensions));
            }

            $visibility->add($regular);
        }

        foreach ($extensions as $index => $extension) {
            $hospitalIds = $extension->visibleHospitalIds($user);
            if ([] === $hospitalIds) {
                continue;
            }

            $visibility->add(sprintf('i.type = :extensionType%1$d AND h.id IN (:extensionHospitals%1$d)', $index));
            $qb->setParameter('extensionType'.$index, $extension::importType())
                ->setParameter('extensionHospitals'.$index, $hospitalIds);
        }

        if (0 === $visibility->count()) {
            $qb->andWhere('1 = 0');
        } else {
            $qb->andWhere($visibility);
        }

        if (!\in_array(UserRole::CLOSURE_BETA, $user->getRoles(), true)) {
            $qb->andWhere('i.type != :hiddenClosureType')
                ->setParameter('hiddenClosureType', ImportType::CLOSURE);
        }

        $hospitalId = $this->importListAccess->sanitizeHospitalId($user, $query->hospitalId);
        if (null !== $hospitalId) {
            $qb->andWhere('h.id = :hospitalId')
                ->setParameter('hospitalId', $hospitalId);
        }

        $ownerId = $this->importListAccess->sanitizeOwnerId($user, $query->ownerId);
        if (null !== $ownerId) {
            $qb->andWhere('owner.id = :ownerId')
                ->setParameter('ownerId', $ownerId);
        }

        if (null !== $query->status && '' !== $query->status) {
            $qb->andWhere('i.status = :status')
                ->setParameter('status', ImportStatus::from($query->status));
        }

        if (null !== $query->createdFrom && '' !== $query->createdFrom) {
            $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $query->createdFrom);
            if (false !== $from) {
                $qb->andWhere('i.createdAt >= :createdFrom')
                    ->setParameter('createdFrom', $from, Types::DATETIME_IMMUTABLE);
            }
        }

        if (null !== $query->createdUntil && '' !== $query->createdUntil) {
            $until = \DateTimeImmutable::createFromFormat('!Y-m-d', $query->createdUntil);
            if (false !== $until) {
                $untilExclusive = $until->modify('+1 day');
                $qb->andWhere('i.createdAt < :createdUntilExclusive')
                    ->setParameter('createdUntilExclusive', $untilExclusive, Types::DATETIME_IMMUTABLE);
            }
        }

        if (null !== $query->batch) {
            $qb->andWhere(\sprintf('i.id IN (SELECT item.importId FROM %s item WHERE IDENTITY(item.run) = :batch)', ImportBatchRunItem::class))
                ->setParameter('batch', $query->batch);
        }

        if (null !== $query->search && '' !== trim($query->search)) {
            $search = '%'.trim($query->search).'%';
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->like('i.name', ':search'),
                $qb->expr()->like('h.name', ':search'),
            ))->setParameter('search', $search);
        }

        // The id tie-breaker keeps rows with equal sort values on a stable page.
        $qb->orderBy(self::sortField($query->sortBy), SortDirections::fromString($query->orderBy))
            ->addOrderBy('i.id', SortDirections::fromString($query->orderBy));

        return $qb;
    }

    /**
     * Maps a public sort key of the import list (see ImportListColumns::SORT_KEYS) to its DQL field.
     */
    public static function sortField(string $sortBy): string
    {
        return match ($sortBy) {
            'hospital' => 'h.name',
            'lastChange' => 'sortDate',
            'status' => 'i.status',
            'type' => 'i.type',
            'name' => 'i.name',
            'rowCount' => 'i.rowCount',
            'rowsPassed' => 'i.rowsPassed',
            'rowsRejected' => 'i.rowsRejected',
            'rowsDeduplicated' => 'i.rowsDeduplicated',
            'fileSize' => 'i.fileSize',
            'runTime' => 'i.runTime',
            'runCount' => 'i.runCount',
            'exportPeriod' => 'i.exportStartsAt',
            default => 'i.createdAt',
        };
    }
}
