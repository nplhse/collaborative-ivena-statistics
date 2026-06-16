<?php

declare(strict_types=1);

namespace App\DataFixtures\Reference;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use Doctrine\ORM\EntityManagerInterface;

final class ReferenceRegistry
{
    /** @var array<string, State> */
    private array $statesByName = [];

    /** @var array<string, DispatchArea> */
    private array $areasByKey = [];

    /** @var array<string, Hospital> */
    private array $hospitalsByName = [];

    /** @var list<Hospital> */
    private array $allHospitals = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function registerState(State $state): void
    {
        $name = (string) $state->getName();
        $this->statesByName[$name] = $state;
    }

    public function registerDispatchArea(DispatchArea $area): void
    {
        $stateName = (string) $area->getState()?->getName();
        $key = $this->areaKey($stateName, (string) $area->getName());
        $this->areasByKey[$key] = $area;
    }

    public function registerHospital(Hospital $hospital): void
    {
        $name = (string) $hospital->getName();
        $this->hospitalsByName[$name] = $hospital;
        $this->allHospitals[] = $hospital;
    }

    public function getState(string $name): State
    {
        if (!isset($this->statesByName[$name])) {
            throw new \RuntimeException(sprintf('Unknown state reference: %s', $name));
        }

        return $this->ensureManagedState($this->statesByName[$name]);
    }

    public function getDispatchArea(string $stateName, string $areaName): DispatchArea
    {
        $key = $this->areaKey($stateName, $areaName);
        if (!isset($this->areasByKey[$key])) {
            throw new \RuntimeException(sprintf('Unknown dispatch area reference: %s / %s', $stateName, $areaName));
        }

        return $this->ensureManagedDispatchArea($this->areasByKey[$key]);
    }

    public function getHospital(string $name): Hospital
    {
        if (!isset($this->hospitalsByName[$name])) {
            throw new \RuntimeException(sprintf('Unknown hospital reference: %s', $name));
        }

        return $this->ensureManagedHospital($this->hospitalsByName[$name]);
    }

    /**
     * @return list<Hospital>
     */
    public function allHospitals(): array
    {
        return array_map(
            $this->ensureManagedHospital(...),
            $this->allHospitals,
        );
    }

    /**
     * @return list<Hospital>
     */
    public function hospitalsMatching(?HospitalTier $tier, ?HospitalLocation $location): array
    {
        return array_values(array_filter(
            $this->allHospitals(),
            static function (Hospital $hospital) use ($tier, $location): bool {
                if ($tier instanceof HospitalTier && $hospital->getTier() !== $tier) {
                    return false;
                }
                if ($location instanceof HospitalLocation && $hospital->getLocation() !== $location) {
                    return false;
                }

                return true;
            },
        ));
    }

    /**
     * Stratified subset: spread across size tiers, prefer participating hospitals.
     *
     * @param list<Hospital> $required
     *
     * @return list<Hospital>
     */
    public function selectActiveHospitals(int $count, array $required = []): array
    {
        $allHospitals = $this->allHospitals();

        $participating = array_values(array_filter(
            $allHospitals,
            static fn (Hospital $hospital): bool => $hospital->isParticipating(),
        ));
        if ([] === $participating) {
            return [];
        }

        $selected = $this->uniqueHospitals(array_values(array_filter(
            $required,
            static fn (Hospital $hospital): bool => $hospital->isParticipating(),
        )));

        if (\count($selected) >= $count) {
            return \array_slice($selected, 0, $count);
        }

        if ($count >= \count($participating)) {
            return $this->uniqueHospitals([...$selected, ...$participating]);
        }

        $selectedIds = $this->hospitalIds($selected);
        $pool = array_values(array_filter(
            $participating,
            static fn (Hospital $hospital): bool => !isset($selectedIds[(int) $hospital->getId()]),
        ));

        usort($pool, static fn (Hospital $a, Hospital $b): int => strcmp((string) $a->getName(), (string) $b->getName()));

        /** @var array<string, list<Hospital>> $bySize */
        $bySize = [];
        foreach ($pool as $hospital) {
            $size = ($hospital->getSize() ?? HospitalSize::MEDIUM)->value;
            $bySize[$size][] = $hospital;
        }

        $sizes = [HospitalSize::LARGE->value, HospitalSize::MEDIUM->value, HospitalSize::SMALL->value];
        $sizeIndex = 0;

        while (\count($selected) < $count) {
            $size = $sizes[$sizeIndex % \count($sizes)];
            if (isset($bySize[$size]) && [] !== $bySize[$size]) {
                $selected[] = array_shift($bySize[$size]);
            }
            ++$sizeIndex;
            if ($sizeIndex > $count * 3 && [] === array_filter($bySize)) {
                break;
            }
        }

        if (\count($selected) < $count) {
            foreach ($pool as $hospital) {
                if (isset($this->hospitalIds($selected)[(int) $hospital->getId()])) {
                    continue;
                }
                $selected[] = $hospital;
                if (\count($selected) >= $count) {
                    break;
                }
            }
        }

        return \array_slice($this->uniqueHospitals($selected), 0, $count);
    }

    /**
     * @return list<Hospital>
     */
    public function participatingHospitals(): array
    {
        return array_values(array_filter(
            $this->allHospitals(),
            static fn (Hospital $hospital): bool => $hospital->isParticipating(),
        ));
    }

    /**
     * @return list<Hospital>
     */
    public function participatingHospitalsMatching(?HospitalTier $tier, ?HospitalLocation $location): array
    {
        return array_values(array_filter(
            $this->participatingHospitals(),
            static function (Hospital $hospital) use ($tier, $location): bool {
                if ($tier instanceof HospitalTier && $hospital->getTier() !== $tier) {
                    return false;
                }
                if ($location instanceof HospitalLocation && $hospital->getLocation() !== $location) {
                    return false;
                }

                return true;
            },
        ));
    }

    public function findHospitalOwnedByUsername(string $username): ?Hospital
    {
        $hospital = $this->entityManager->createQueryBuilder()
            ->select('h')
            ->from(Hospital::class, 'h')
            ->join('h.owner', 'u')
            ->where('u.username = :username')
            ->setParameter('username', $username)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $hospital instanceof Hospital ? $this->ensureManagedHospital($hospital) : null;
    }

    /**
     * @param list<Hospital> $hospitals
     *
     * @return list<Hospital>
     */
    private function uniqueHospitals(array $hospitals): array
    {
        $unique = [];
        foreach ($hospitals as $hospital) {
            $unique[(int) $hospital->getId()] = $this->ensureManagedHospital($hospital);
        }

        return array_values($unique);
    }

    /**
     * @param list<Hospital> $hospitals
     *
     * @return array<int, true>
     */
    private function hospitalIds(array $hospitals): array
    {
        $ids = [];
        foreach ($hospitals as $hospital) {
            $ids[(int) $hospital->getId()] = true;
        }

        return $ids;
    }

    private function ensureManagedState(State $state): State
    {
        if ($this->entityManager->contains($state)) {
            return $state;
        }

        $name = (string) $state->getName();
        $managed = $this->entityManager->getRepository(State::class)->findOneBy(['name' => $name]);
        if (!$managed instanceof State) {
            throw new \RuntimeException(sprintf('State "%s" is not persisted.', $name));
        }

        $this->statesByName[$name] = $managed;

        return $managed;
    }

    private function ensureManagedDispatchArea(DispatchArea $area): DispatchArea
    {
        if ($this->entityManager->contains($area)) {
            return $area;
        }

        $stateName = (string) $area->getState()?->getName();
        $areaName = (string) $area->getName();
        $key = $this->areaKey($stateName, $areaName);

        $managed = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(DispatchArea::class, 'a')
            ->join('a.state', 's')
            ->where('a.name = :areaName')
            ->andWhere('s.name = :stateName')
            ->setParameter('areaName', $areaName)
            ->setParameter('stateName', $stateName)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$managed instanceof DispatchArea) {
            throw new \RuntimeException(sprintf('Dispatch area "%s / %s" is not persisted.', $stateName, $areaName));
        }

        $this->areasByKey[$key] = $managed;

        return $managed;
    }

    private function ensureManagedHospital(Hospital $hospital): Hospital
    {
        if ($this->entityManager->contains($hospital)) {
            return $hospital;
        }

        $name = (string) $hospital->getName();
        $managed = $this->entityManager->getRepository(Hospital::class)->findOneBy(['name' => $name]);
        if (!$managed instanceof Hospital) {
            throw new \RuntimeException(sprintf('Hospital "%s" is not persisted.', $name));
        }

        $this->hospitalsByName[$name] = $managed;

        return $managed;
    }

    private function areaKey(string $stateName, string $areaName): string
    {
        return $stateName.'::'.$areaName;
    }
}
