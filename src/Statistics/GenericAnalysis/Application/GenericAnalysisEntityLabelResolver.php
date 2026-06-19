<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Infrastructure\Repository\AssignmentRepository;
use App\Allocation\Infrastructure\Repository\DepartmentRepository;
use App\Allocation\Infrastructure\Repository\DispatchAreaRepository;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Allocation\Infrastructure\Repository\InfectionRepository;
use App\Allocation\Infrastructure\Repository\OccasionRepository;
use App\Allocation\Infrastructure\Repository\SpecialityRepository;
use App\Allocation\Infrastructure\Repository\StateRepository;
use App\Statistics\GenericAnalysis\Application\Contract\GenericAnalysisEntityLabelResolverInterface;

final readonly class GenericAnalysisEntityLabelResolver implements GenericAnalysisEntityLabelResolverInterface
{
    /** @var list<string> */
    private const array ENTITY_DIMENSION_KEYS = [
        'hospital',
        'state',
        'dispatchArea',
        'department',
        'speciality',
        'occasion',
        'assignment',
        'indication',
        'infection',
    ];

    public function __construct(
        private HospitalLookupInterface $hospitalLookup,
        private StateRepository $stateRepository,
        private DispatchAreaRepository $dispatchAreaRepository,
        private DepartmentRepository $departmentRepository,
        private SpecialityRepository $specialityRepository,
        private OccasionRepository $occasionRepository,
        private AssignmentRepository $assignmentRepository,
        private IndicationNormalizedRepository $indicationNormalizedRepository,
        private InfectionRepository $infectionRepository,
    ) {
    }

    #[\Override]
    public function supports(string $dimensionKey): bool
    {
        return \in_array($dimensionKey, self::ENTITY_DIMENSION_KEYS, true);
    }

    /** Maximum entity IDs resolved in one label lookup batch. */
    private const int MAX_RESOLVE_IDS = 500;

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    #[\Override]
    public function resolve(string $dimensionKey, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $uniqueIds = array_values(array_unique($ids));
        if (\count($uniqueIds) > self::MAX_RESOLVE_IDS) {
            $uniqueIds = \array_slice($uniqueIds, 0, self::MAX_RESOLVE_IDS);
        }

        return match ($dimensionKey) {
            'hospital' => $this->hospitalLookup->findNamesByIds($uniqueIds),
            'state' => $this->loadIdNameMap($this->stateRepository, 's', $uniqueIds),
            'dispatchArea' => $this->loadIdNameMap($this->dispatchAreaRepository, 'da', $uniqueIds),
            'department' => $this->loadIdNameMap($this->departmentRepository, 'd', $uniqueIds),
            'speciality' => $this->loadIdNameMap($this->specialityRepository, 'sp', $uniqueIds),
            'occasion' => $this->loadIdNameMap($this->occasionRepository, 'oc', $uniqueIds),
            'assignment' => $this->loadIdNameMap($this->assignmentRepository, 'a', $uniqueIds),
            'indication' => $this->loadIdNameMap($this->indicationNormalizedRepository, 'i', $uniqueIds),
            'infection' => $this->loadIdNameMap($this->infectionRepository, 'i', $uniqueIds),
            default => [],
        };
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    private function loadIdNameMap(object $repository, string $alias, array $ids): array
    {
        /** @var list<array{id: int|string, name: ?string}> $rows */
        $rows = $repository->createQueryBuilder($alias)
            ->select(sprintf('%s.id', $alias), sprintf('%s.name', $alias))
            ->andWhere(sprintf('%s.id IN (:ids)', $alias))
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getArrayResult();

        $names = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $name = $row['name'];
            $names[$id] = (null !== $name && '' !== $name) ? $name : (string) $id;
        }

        return $names;
    }
}
