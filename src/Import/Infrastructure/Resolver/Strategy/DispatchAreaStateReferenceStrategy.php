<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Resolver\Strategy;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Infrastructure\Repository\DispatchAreaRepository;
use App\Import\Application\Exception\ReferenceNotFoundException;
use Doctrine\ORM\EntityManagerInterface;

final class DispatchAreaStateReferenceStrategy
{
    /** @var array<string,int> */
    private array $dispatchIdByKey = [];

    /** @var array<int,int> */
    private array $stateIdByDispatchId = [];

    public function __construct(
        private readonly DispatchAreaRepository $dispatchRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function warm(): void
    {
        if ([] !== $this->dispatchIdByKey) {
            return;
        }

        foreach ($this->dispatchRepo->findBy([], ['name' => 'ASC']) as $area) {
            $areaId = $area->getId();
            $state = $area->getState();
            $stateId = $state?->getId();

            if (null === $areaId || null === $stateId) {
                throw new \DomainException(\sprintf('DispatchArea "%s" is invalid: id or stateId is null.', (string) $area->getName()));
            }

            $key = $this->key((string) $area->getName());
            $this->dispatchIdByKey[$key] = $areaId;
            $this->stateIdByDispatchId[$areaId] = $stateId;
        }
    }

    /**
     * @param object $entity must expose setDispatchArea() and setState()
     */
    public function apply(object $entity, string $dispatchAreaName): void
    {
        $key = $this->key($dispatchAreaName);

        $dispatchId = $this->dispatchIdByKey[$key] ?? null;
        if (null === $dispatchId) {
            throw ReferenceNotFoundException::forField('dispatchArea', $dispatchAreaName);
        }

        /** @var DispatchArea $dispatchRef */
        $dispatchRef = $this->em->getReference(DispatchArea::class, $dispatchId);
        $entity->setDispatchArea($dispatchRef);

        $stateId = $this->stateIdByDispatchId[$dispatchId] ?? null;
        if (null === $stateId) {
            throw ReferenceNotFoundException::forField('state for dispatchArea', $dispatchAreaName);
        }

        /** @var State $stateRef */
        $stateRef = $this->em->getReference(State::class, $stateId);
        $entity->setState($stateRef);
    }

    private function key(string $name): string
    {
        $s = \mb_strtolower(\trim($name), 'UTF-8');
        $normalized = \preg_replace('/\s+/', ' ', $s);

        return $normalized ?? $s;
    }
}
