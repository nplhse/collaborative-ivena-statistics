<?php

namespace App\Service\Import\Resolver;

use App\Entity\Allocation;
use App\Entity\DispatchArea;
use App\Entity\State;
use App\Repository\DispatchAreaRepository;
use App\Service\Import\Contracts\AllocationEntityResolverInterface;
use App\Service\Import\DTO\AllocationRowDTO;
use App\Service\Import\Exception\ReferenceNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem('allocation.import_resolver')]
final class AllocationDispatchAreaResolver implements AllocationEntityResolverInterface
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

    #[\Override]
    public function warm(): void
    {
        foreach ($this->dispatchRepo->findBy([], ['name' => 'ASC']) as $area) {
            $areaId = $area->getId();
            $state = $area->getState();
            $stateId = $state?->getId();

            if (null === $areaId || null === $stateId) {
                throw new \DomainException(sprintf('DispatchArea "%s" is invalid: id or stateId is null.', (string) $area->getName()));
            }

            $key = self::key((string) $area->getName());

            $this->dispatchIdByKey[$key] = $areaId;
            $this->stateIdByDispatchId[$areaId] = $stateId;
        }
    }

    #[\Override]
    public function supports(Allocation $entity, AllocationRowDTO $dto): bool
    {
        return true;
    }

    #[\Override]
    public function apply(Allocation $entity, AllocationRowDTO $dto): void
    {
        $key = self::key((string) $dto->dispatchArea);

        $dispatchId = $this->dispatchIdByKey[$key] ?? null;
        if (null === $dispatchId) {
            throw ReferenceNotFoundException::forField('dispatchArea', $dto->dispatchArea);
        }

        /** @var DispatchArea $dispatchRef */
        $dispatchRef = $this->em->getReference(DispatchArea::class, $dispatchId);
        $entity->setDispatchArea($dispatchRef);

        $stateId = $this->stateIdByDispatchId[$dispatchId] ?? null;
        if (null === $stateId) {
            throw ReferenceNotFoundException::forField('state for dispatchArea', $dto->dispatchArea);
        }

        /** @var State $stateRef */
        $stateRef = $this->em->getReference(State::class, $stateId);
        $entity->setState($stateRef);
    }

    private static function key(string $name): string
    {
        $s = \mb_strtolower(\trim($name), 'UTF-8');
        $normalized = \preg_replace('/\s+/', ' ', $s);

        return $normalized ?? $s;
    }
}
