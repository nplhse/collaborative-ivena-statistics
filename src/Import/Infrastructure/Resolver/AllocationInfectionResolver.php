<?php

namespace App\Import\Infrastructure\Resolver;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Infrastructure\Repository\InfectionRepository;
use App\Import\Application\Contracts\AllocationEntityResolverInterface;
use App\Import\Application\DTO\AllocationRowDTO;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem('allocation.import_resolver')]
final class AllocationInfectionResolver implements AllocationEntityResolverInterface
{
    /** @var array<string,int> */
    private array $infectionIdByKey = [];

    public function __construct(
        private readonly InfectionRepository $infectionRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function warm(): void
    {
        foreach ($this->infectionRepository->findBy([], ['name' => 'ASC']) as $infection) {
            $infectionId = $infection->getId();

            if (null === $infectionId) {
                throw new \DomainException(sprintf('Infection "%s" is invalid: id is null.', (string) $infection->getName()));
            }

            $key = self::key((string) $infection->getName());

            $this->infectionIdByKey[$key] = $infectionId;
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
        $key = self::key((string) $dto->infection);

        $infectionId = $this->infectionIdByKey[$key] ?? null;

        if (null === $infectionId) {
            return;
        }

        /** @var Infection $infectionRef */
        $infectionRef = $this->em->getReference(Infection::class, $infectionId);
        $entity->setInfection($infectionRef);
    }

    private static function key(string $name): string
    {
        $s = \mb_strtolower(\trim($name), 'UTF-8');
        $normalized = \preg_replace('/\s+/', ' ', $s);

        return $normalized ?? $s;
    }
}
