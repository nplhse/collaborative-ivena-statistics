<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Restore;

use App\Allocation\Application\ReferenceCatalog\Import\ReferenceCatalogImportApplier;
use App\Allocation\Application\ReferenceCatalog\Import\ReferenceCatalogImportPlanner;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackage;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\District;
use App\Statistics\Application\Contract\MaterializedViewRefresherInterface;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Replaces the whole catalog with a complete package (restore, ADR 020).
 *
 * Refuses while any row outside the catalog refers to it. Deletes the catalog, aliases and
 * join tables and inserts the package in one transaction, keeping the package ids; a failure
 * leaves the old catalog in place. Never deletes operational data or permissions.
 *
 * District rows stay, because their geometry is not in a package (ADR 041). A package with districts
 * sets their name, kind and assignment; one without keeps the assignments whose area it still has.
 */
final readonly class ReferenceCatalogRestorer
{
    /** Catalog tables in delete order (dependents first). */
    private const array PURGE_ORDER = [
        'department_alias',
        'speciality_alias',
        'indication_group_indication_normalized',
        'indication_group',
        'indication_raw',
        'indication_normalized',
        'hospital',
        'dispatch_area',
        'department',
        'speciality',
        'assignment',
        'occasion',
        'infection',
        'secondary_transport',
        'state',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReferenceCatalogDependencyInspector $inspector,
        private ReferenceCatalogImportPlanner $planner,
        private ReferenceCatalogImportApplier $applier,
        private MaterializedViewRefresherInterface $materializedViews,
        #[Autowire(service: 'cache.allocation.reference_data')]
        private CacheItemPoolInterface $referenceDataCache,
    ) {
    }

    /**
     * Rows the restore would delete, per type; aliases as "<type>-alias".
     *
     * @return array<string, int>
     */
    public function currentCounts(): array
    {
        $connection = $this->entityManager->getConnection();
        $counts = [];
        foreach (ReferenceCatalogType::importOrder() as $type) {
            if (ReferenceCatalogType::District === $type) {
                continue;
            }

            $table = $this->entityManager->getClassMetadata($type->entityClass())->getTableName();
            $counts[$type->value] = (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
            if (ReferenceCatalogType::Department === $type || ReferenceCatalogType::Speciality === $type) {
                $counts[$type->value.'-alias'] = (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s_alias', $table));
            }
        }

        return $counts;
    }

    /**
     * @throws ReferenceCatalogRestoreBlockedException when rows outside the catalog refer to it
     */
    public function restore(ReferenceCatalogPackage $package, User $user): void
    {
        if (!$package->meta->complete) {
            throw new \InvalidArgumentException('A restore needs a complete package (meta.complete = true).');
        }

        $userId = $user->getId();
        $this->entityManager->wrapInTransaction(function () use ($package, $userId): void {
            $this->replaceCatalog($package, $userId);
        });

        $this->materializedViews->refresh();
        $this->referenceDataCache->clear();
    }

    private function replaceCatalog(ReferenceCatalogPackage $package, ?int $userId): void
    {
        // Checked inside the transaction, so nothing can slip in between check and delete.
        $blockers = $this->inspector->blockers();
        if ([] !== $blockers) {
            throw new ReferenceCatalogRestoreBlockedException($blockers);
        }

        $connection = $this->entityManager->getConnection();
        /** @var array<string, string> $previousAssignments */
        $previousAssignments = $connection->fetchAllKeyValue('SELECT d.ags, a.public_id FROM district d JOIN dispatch_area a ON a.id = d.dispatch_area_id');

        foreach (self::PURGE_ORDER as $table) {
            $connection->executeStatement(sprintf('DELETE FROM %s', $table));
        }
        $this->entityManager->clear();

        $importingUser = $this->entityManager->find(User::class, $userId);
        if (!$importingUser instanceof User) {
            throw new \RuntimeException('The importing user no longer exists.');
        }

        $plan = $this->planner->plan($package);
        if ($plan->hasConflicts()) {
            throw new \LogicException('A restore into an empty catalog must not have conflicts.');
        }

        $this->applier->write($plan, $importingUser);
        $this->restoreDistricts($package, $previousAssignments);
    }

    /**
     * @param array<string, string> $previousAssignments AGS → public id of the dispatch area before the restore
     */
    private function restoreDistricts(ReferenceCatalogPackage $package, array $previousAssignments): void
    {
        $areas = [];
        foreach ($this->entityManager->getRepository(DispatchArea::class)->findAll() as $area) {
            $areas[strtolower($area->getPublicIdString())] = $area;
        }

        $entries = [];
        foreach ($package->districts as $entry) {
            $entries[$entry->ags] = $entry;
        }
        $packageHasDistricts = \in_array(ReferenceCatalogType::District->value, $package->meta->types, true);

        foreach ($this->entityManager->getRepository(District::class)->findAll() as $district) {
            $ags = $district->getAgs();
            if (!$packageHasDistricts) {
                $district->setDispatchArea($areas[strtolower($previousAssignments[$ags] ?? '')] ?? null);
            } elseif (isset($entries[$ags])) {
                $entry = $entries[$ags];
                $district
                    ->setName($entry->name)
                    ->setKind($entry->kind)
                    ->setDispatchArea(null === $entry->dispatchArea ? null : $areas[strtolower($entry->dispatchArea)] ?? null);
            }
        }

        $this->entityManager->flush();
    }
}
