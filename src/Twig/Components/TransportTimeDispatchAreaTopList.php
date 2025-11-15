<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Model\Scope;
use App\Repository\DispatchAreaRepository;
use App\Service\Statistics\TransportTimeDispatchAreaTopReader;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent(name: 'TransportTimeDispatchAreaTopList')]
final class TransportTimeDispatchAreaTopList
{
    /**
     * Current scope, passed from template.
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public Scope $scope;

    /**
     * @var list<array{
     *   dispatchAreaId:int,
     *   name:string,
     *   total:int,
     *   share:float,
     *   withPhysician:int,
     *   withPhysicianShare:float
     * }>
     */
    public array $rows = [];

    public function __construct(
        private readonly TransportTimeDispatchAreaTopReader $reader,
        private readonly DispatchAreaRepository $dispatchAreaRepository,
    ) {
    }

    #[PostMount]
    public function init(): void
    {
        // 1) Load raw top list from agg_allocations_transport_time_dim
        $raw = $this->reader->readTopByDispatchArea($this->scope, 10);

        if ([] === $raw) {
            $this->rows = [];

            return;
        }

        // 2) Collect all dispatch area IDs
        $ids = array_unique(array_map(static fn (array $r): int => $r['dispatchAreaId'], $raw));

        // 3) Fetch dispatch areas in one go
        $areas = $this->dispatchAreaRepository->findBy(['id' => $ids]);

        $nameById = [];
        foreach ($areas as $area) {
            // adjust to your entity API
            $nameById[$area->getId()] = (string) $area->getName();
        }

        // 4) Merge names into the rows
        $rows = [];
        foreach ($raw as $r) {
            $id = $r['dispatchAreaId'];
            $rows[] = [
                'dispatchAreaId' => $id,
                'name' => $nameById[$id] ?? ('Dispatch Area #'.$id),
                'total' => $r['total'],
                'share' => $r['share'],
                'withPhysician' => $r['withPhysician'],
                'withPhysicianShare' => $r['withPhysicianShare'],
            ];
        }

        $this->rows = $rows;
    }

    public function hasData(): bool
    {
        return [] !== $this->rows;
    }
}
