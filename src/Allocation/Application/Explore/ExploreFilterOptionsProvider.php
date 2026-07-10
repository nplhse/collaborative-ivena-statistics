<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore;

use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Infrastructure\Repository\AssignmentRepository;
use App\Allocation\Infrastructure\Repository\DepartmentRepository;
use App\Allocation\Infrastructure\Repository\DispatchAreaRepository;
use App\Allocation\Infrastructure\Repository\IndicationGroupRepository;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Allocation\Infrastructure\Repository\InfectionRepository;
use App\Allocation\Infrastructure\Repository\OccasionRepository;
use App\Allocation\Infrastructure\Repository\SecondaryTransportRepository;
use App\Allocation\Infrastructure\Repository\SpecialityRepository;
use App\Allocation\Infrastructure\Repository\StateRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class ExploreFilterOptionsProvider
{
    private const int TTL_SECONDS = 3600;

    private const string CACHE_KEY_STATES = 'explore_filter.states';

    private const string CACHE_KEY_DISPATCH_AREAS = 'explore_filter.dispatch_areas';

    private const string CACHE_KEY_INDICATIONS = 'explore_filter.indications';

    private const string CACHE_KEY_SECONDARY_TRANSPORTS = 'explore_filter.secondary_transports';

    private const string CACHE_KEY_INFECTIONS = 'explore_filter.infections';

    private const string CACHE_KEY_DEPARTMENTS = 'explore_filter.departments';

    private const string CACHE_KEY_SPECIALITIES = 'explore_filter.specialities';

    private const string CACHE_KEY_ASSIGNMENTS = 'explore_filter.assignments';

    private const string CACHE_KEY_OCCASIONS = 'explore_filter.occasions';

    private const string CACHE_KEY_INDICATION_GROUPS = 'explore_filter.indication_groups';

    public function __construct(
        #[Autowire(service: 'cache.allocation.reference_data')]
        private CacheInterface $cache,
        private StateRepository $stateRepository,
        private DispatchAreaRepository $dispatchAreaRepository,
        private IndicationNormalizedRepository $indicationNormalizedRepository,
        private IndicationGroupRepository $indicationGroupRepository,
        private SecondaryTransportRepository $secondaryTransportRepository,
        private InfectionRepository $infectionRepository,
        private DepartmentRepository $departmentRepository,
        private SpecialityRepository $specialityRepository,
        private AssignmentRepository $assignmentRepository,
        private OccasionRepository $occasionRepository,
    ) {
    }

    /**
     * @return array{
     *     states: list<array{id: int, name: string}>,
     *     dispatchAreas: list<array{id: int, name: string}>,
     *     indications: list<array{id: int, code: int, name: string}>,
     *     secondaryTransports: list<array{id: int, name: string}>,
     *     infections: list<array{id: int, name: string}>,
     *     departments: list<array{id: int, name: string}>,
     *     specialities: list<array{id: int, name: string}>,
     *     assignments: list<array{id: int, name: string}>,
     *     occasions: list<array{id: int, name: string}>,
     * }
     */
    public function allocationListOptions(): array
    {
        return [
            'states' => $this->states(),
            'dispatchAreas' => $this->dispatchAreas(),
            'indications' => $this->indications(),
            'secondaryTransports' => $this->secondaryTransports(),
            'infections' => $this->infections(),
            'departments' => $this->departments(),
            'specialities' => $this->specialities(),
            'assignments' => $this->assignments(),
            'occasions' => $this->occasions(),
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function states(): array
    {
        return $this->cache->get(self::CACHE_KEY_STATES, function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL_SECONDS);

            return array_map(
                static fn (State $state): array => [
                    'id' => (int) $state->getId(),
                    'name' => (string) $state->getName(),
                ],
                $this->stateRepository->findAll(),
            );
        });
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function dispatchAreas(): array
    {
        return $this->cache->get(self::CACHE_KEY_DISPATCH_AREAS, function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL_SECONDS);

            return array_map(
                static fn (DispatchArea $dispatchArea): array => [
                    'id' => (int) $dispatchArea->getId(),
                    'name' => (string) $dispatchArea->getName(),
                ],
                $this->dispatchAreaRepository->findAll(),
            );
        });
    }

    /**
     * @return list<array{id: int, code: int, name: string}>
     */
    public function indications(): array
    {
        return $this->cache->get(self::CACHE_KEY_INDICATIONS, function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL_SECONDS);

            return array_map(
                static fn (IndicationNormalized $indication): array => [
                    'id' => (int) $indication->getId(),
                    'code' => (int) $indication->getCode(),
                    'name' => (string) $indication->getName(),
                ],
                $this->indicationNormalizedRepository->findAll(),
            );
        });
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function secondaryTransports(): array
    {
        return $this->cache->get(self::CACHE_KEY_SECONDARY_TRANSPORTS, function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL_SECONDS);

            return $this->mapNamedEntities($this->secondaryTransportRepository->findBy([], ['name' => 'ASC']));
        });
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function infections(): array
    {
        return $this->cache->get(self::CACHE_KEY_INFECTIONS, function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL_SECONDS);

            return $this->mapNamedEntities($this->infectionRepository->findBy([], ['name' => 'ASC']));
        });
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function departments(): array
    {
        return $this->cache->get(self::CACHE_KEY_DEPARTMENTS, function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL_SECONDS);

            return $this->mapNamedEntities($this->departmentRepository->findBy([], ['name' => 'ASC']));
        });
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function specialities(): array
    {
        return $this->cache->get(self::CACHE_KEY_SPECIALITIES, function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL_SECONDS);

            return $this->mapNamedEntities($this->specialityRepository->findBy([], ['name' => 'ASC']));
        });
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function assignments(): array
    {
        return $this->cache->get(self::CACHE_KEY_ASSIGNMENTS, function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL_SECONDS);

            return $this->mapNamedEntities($this->assignmentRepository->findBy([], ['name' => 'ASC']));
        });
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function occasions(): array
    {
        return $this->cache->get(self::CACHE_KEY_OCCASIONS, function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL_SECONDS);

            return $this->mapNamedEntities($this->occasionRepository->findBy([], ['name' => 'ASC']));
        });
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function indicationGroups(): array
    {
        return $this->cache->get(self::CACHE_KEY_INDICATION_GROUPS, function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL_SECONDS);

            return array_map(
                static fn (IndicationGroup $group): array => [
                    'id' => (int) $group->getId(),
                    'name' => (string) $group->getName(),
                ],
                $this->indicationGroupRepository->findBy([], ['name' => 'ASC']),
            );
        });
    }

    /**
     * @template T of Assignment|Department|Infection|Occasion|SecondaryTransport|Speciality
     *
     * @param list<T> $entities
     *
     * @return list<array{id: int, name: string}>
     */
    private function mapNamedEntities(array $entities): array
    {
        return array_map(
            static fn (Assignment|Department|Infection|Occasion|SecondaryTransport|Speciality $entity): array => [
                'id' => (int) $entity->getId(),
                'name' => (string) $entity->getName(),
            ],
            $entities,
        );
    }
}
