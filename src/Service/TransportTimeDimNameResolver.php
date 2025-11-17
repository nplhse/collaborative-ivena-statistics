<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AssignmentRepository;
use App\Repository\DispatchAreaRepository;
use App\Repository\IndicationNormalizedRepository;
use App\Repository\OccasionRepository;
use App\Repository\SpecialityRepository;
use App\Repository\StateRepository;

final class TransportTimeDimNameResolver
{
    /**
     * @var array<string, array<int,string>> [dimType][id] => name
     */
    private array $namesByDimType = [];

    /**
     * @var array<string,bool> [dimType] => loaded?
     */
    private array $loaded = [];

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly AssignmentRepository $assignmentRepository,
        private readonly DispatchAreaRepository $dispatchAreaRepository,
        private readonly OccasionRepository $occasionRepository,
        private readonly IndicationNormalizedRepository $indicationRepository,
        private readonly SpecialityRepository $specialityRepository,
        private readonly StateRepository $stateRepository,
    ) {
    }

    public function preload(string $dimType): void
    {
        if (($this->loaded[$dimType] ?? false) === true) {
            return;
        }

        $repository = match ($dimType) {
            'assignment' => $this->assignmentRepository,
            'dispatch_area' => $this->dispatchAreaRepository,
            'occasion' => $this->occasionRepository,
            'indication', 'indication_normalized' => $this->indicationRepository,
            'speciality' => $this->specialityRepository,
            'state' => $this->stateRepository,
            default => null,
        };

        if (null === $repository) {
            $this->namesByDimType[$dimType] = [];
            $this->loaded[$dimType] = true;

            return;
        }

        $qb = $repository->createQueryBuilder('e')
            ->select('e.id, e.name');

        /** @var list<array{id:int|string,name:string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        /** @var array<int,string> $map */
        $map = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $map[$id] = $row['name'];
        }

        $this->namesByDimType[$dimType] = $map;
        $this->loaded[$dimType] = true;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int,string> map[id] => name
     */
    public function resolve(string $dimType, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        if (($this->loaded[$dimType] ?? false) === false) {
            $this->preload($dimType);
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        $all = $this->namesByDimType[$dimType] ?? [];

        return array_intersect_key($all, array_flip($ids));
    }

    public function fallbackLabel(string $dimType, int $id): string
    {
        return ucfirst(str_replace('_', ' ', $dimType)).' #'.$id;
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function labelOrFallback(string $dimType, int $id): string
    {
        $names = $this->resolve($dimType, [$id]);

        return $names[$id] ?? $this->fallbackLabel($dimType, $id);
    }
}
