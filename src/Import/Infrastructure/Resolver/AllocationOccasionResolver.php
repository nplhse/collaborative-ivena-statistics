<?php

namespace App\Import\Infrastructure\Resolver;

use App\Entity\Allocation;
use App\Entity\Occasion;
use App\Import\Application\Contracts\AllocationEntityResolverInterface;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Repository\OccasionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem('allocation.import_resolver')]
final class AllocationOccasionResolver implements AllocationEntityResolverInterface
{
    /** @var array<string,int> */
    private array $occasionIdByKey = [];

    public function __construct(
        private readonly OccasionRepository $occasionRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function warm(): void
    {
        foreach ($this->occasionRepository->findBy([], ['name' => 'ASC']) as $occasion) {
            $occasionId = $occasion->getId();

            if (null === $occasionId) {
                throw new \DomainException(sprintf('Occasion "%s" is invalid: id is null.', (string) $occasion->getName()));
            }

            $key = self::key((string) $occasion->getName());

            $this->occasionIdByKey[$key] = $occasionId;
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
        $key = self::key((string) $dto->occasion);

        $occasionId = $this->occasionIdByKey[$key] ?? null;
        if (null === $occasionId) {
            return;
        }

        /** @var Occasion $occasionRef */
        $occasionRef = $this->em->getReference(Occasion::class, $occasionId);
        $entity->setOccasion($occasionRef);
    }

    private static function key(string $name): string
    {
        $s = \mb_strtolower(\trim($name), 'UTF-8');
        $normalized = \preg_replace('/\s+/', ' ', $s);

        return $normalized ?? $s;
    }
}
