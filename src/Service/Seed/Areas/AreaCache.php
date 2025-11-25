<?php

namespace App\Service\Seed\Areas;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\State;
use Doctrine\ORM\EntityManagerInterface;

/** @psalm-suppress ClassMustBeFinal */
class AreaCache
{
    /** @var array<string,int> */
    private array $stateIdByName = [];

    /** @var array<string,int> key: "STATE|AREA" */
    private array $areaIdByKey = [];

    private bool $warmedUp = false;

    public function __construct(
        private readonly EntityManagerInterface $em)
    {
    }

    public function warmUp(): void
    {
        if ($this->warmedUp) {
            return;
        }

        $stateRows = $this->em->createQueryBuilder()
            ->from(State::class, 's')
            ->select('s.id AS id, s.name AS name')
            ->getQuery()
            ->getArrayResult();

        foreach ($stateRows as $row) {
            $this->stateIdByName[$row['name']] = (int) $row['id'];
        }

        $areaRows = $this->em->createQueryBuilder()
            ->from(DispatchArea::class, 'a')
            ->join('a.state', 's')
            ->select('a.id AS id, a.name AS area, s.name AS state')
            ->getQuery()
            ->getArrayResult();

        foreach ($areaRows as $row) {
            $key = $this->makeAreaKey($row['state'], $row['area']);
            $this->areaIdByKey[$key] = (int) $row['id'];
        }

        $this->warmedUp = true;
    }

    public function hasState(string $stateName): bool
    {
        return isset($this->stateIdByName[$stateName]);
    }

    public function hasArea(string $stateName, string $areaName): bool
    {
        return isset($this->areaIdByKey[$this->makeAreaKey($stateName, $areaName)]);
    }

    public function getStateRef(string $stateName): State
    {
        $id = $this->stateIdByName[$stateName] ?? null;

        if (null === $id) {
            throw new \RuntimeException("State '{$stateName}' not found in RegionCache.");
        }

        /** @var State $ref */
        $ref = $this->em->getReference(State::class, $id);

        return $ref;
    }

    public function getAreaRef(string $stateName, string $areaName): DispatchArea
    {
        $id = $this->areaIdByKey[$this->makeAreaKey($stateName, $areaName)] ?? null;

        if (null === $id) {
            throw new \RuntimeException("DispatchArea '{$areaName}' in state '{$stateName}' not found in RegionCache.");
        }

        /** @var DispatchArea $ref */
        $ref = $this->em->getReference(DispatchArea::class, $id);

        return $ref;
    }

    private function makeAreaKey(string $stateName, string $areaName): string
    {
        return $stateName.'|'.$areaName;
    }
}
