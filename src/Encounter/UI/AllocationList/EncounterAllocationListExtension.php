<?php

declare(strict_types=1);

namespace App\Encounter\UI\AllocationList;

use App\Allocation\Application\ListExtension\AllocationListExtensionFilter;
use App\Allocation\Application\ListExtension\AllocationListExtensionInterface;
use App\Encounter\Application\Display\EncounterLinkRegistry;
use App\Encounter\Domain\Entity\Encounter;
use App\Shared\UI\Twig\DataTable\DataTableColumns;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Filter "Associated encounter" and column "Encounter" of the Explore allocation list (#1203), contributed through
 * the Allocation extension point so Allocation does not depend on Encounter.
 *
 * Access is decided per hospital (ADR 028): only users with VIEW_CASES on at least one hospital see filter and
 * column. An active filter keeps only allocations of those hospitals, for "without" as well, because for other
 * hospitals it is unknown whether an encounter exists. A cell is filled only for rows of those hospitals.
 * The effective assignment is `encounter.allocation_id` (#1300); proposals are not shown here.
 *
 * @psalm-suppress UnusedClass Tagged via AllocationListExtensionInterface.
 */
final readonly class EncounterAllocationListExtension implements AllocationListExtensionInterface
{
    public const string KEY = 'encounter';

    public const string WITH = 'with';

    public const string WITHOUT = 'without';

    public function __construct(
        private EncounterCaseHospitals $caseHospitals,
        private EntityManagerInterface $entityManager,
        private EncounterLinkRegistry $links,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function isAvailableFor(?User $user): bool
    {
        return $user instanceof User && [] !== $this->caseHospitals->forUser($user);
    }

    #[\Override]
    public function filter(): AllocationListExtensionFilter
    {
        return new AllocationListExtensionFilter(
            label: 'encounter.allocation_list.filter.label',
            allLabel: 'encounter.allocation_list.filter.all',
            choices: [
                self::WITH => 'encounter.allocation_list.filter.with',
                self::WITHOUT => 'encounter.allocation_list.filter.without',
            ],
            domain: 'encounter',
            help: 'encounter.allocation_list.filter.help',
        );
    }

    #[\Override]
    public function applyFilter(QueryBuilder $query, string $value, ?User $user): void
    {
        if (!$user instanceof User) {
            $query->andWhere('1 = 0');

            return;
        }

        $hospitals = $this->caseHospitals->forUser($user);
        $query->andWhere('a.hospital IN (:encounterCaseHospitalIds)')
            ->setParameter('encounterCaseHospitalIds', [] === $hospitals ? [0] : $hospitals, ArrayParameterType::INTEGER);

        $linked = \sprintf('SELECT enc_list.id FROM %s enc_list WHERE enc_list.allocation = a', Encounter::class);
        $query->andWhere(self::WITH === $value ? \sprintf('EXISTS (%s)', $linked) : \sprintf('NOT EXISTS (%s)', $linked));
    }

    #[\Override]
    public function column(): array
    {
        return DataTableColumns::configurable(self::KEY, 'encounter.allocation_list.column', 'custom', [
            'labelDomain' => 'encounter',
            'visible' => false,
            'cellTemplate' => '@Encounter/allocation_list/_cell.html.twig',
        ]);
    }

    #[\Override]
    public function showsColumnFor(string $filterValue): bool
    {
        return self::WITH === $filterValue;
    }

    #[\Override]
    public function cellsFor(array $rows, ?User $user): array
    {
        if (!$user instanceof User) {
            return [];
        }

        $visible = [];
        foreach ($rows as $row) {
            if ($this->caseHospitals->maySee($user, $row['hospital_id'])) {
                $visible[] = $row['id'];
            }
        }
        if ([] === $visible) {
            return [];
        }

        /** @var list<array{allocationId: int|string, encounterId: int|string}> $pairs */
        $pairs = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(e.allocation) AS allocationId', 'e.id AS encounterId')
            ->from(Encounter::class, 'e')
            ->where('e.allocation IN (:allocationIds)')
            ->setParameter('allocationIds', $visible, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $encounterByAllocation = [];
        foreach ($pairs as $pair) {
            $encounterByAllocation[(int) $pair['allocationId']] = (int) $pair['encounterId'];
        }
        $urls = [] === $encounterByAllocation ? [] : $this->links->forEncounters(array_values($encounterByAllocation));

        // Visible rows without an encounter get "none"; rows of other hospitals get no value at all.
        $cells = [];
        foreach ($visible as $allocationId) {
            $encounterId = $encounterByAllocation[$allocationId] ?? null;
            $cells[$allocationId] = null === $encounterId
                ? ['linked' => false]
                : ['linked' => true, 'encounter_id' => $encounterId, 'url' => $urls[$encounterId] ?? null];
        }

        return $cells;
    }

    #[\Override]
    public function dataTables(): array
    {
        return ['encounter'];
    }
}
