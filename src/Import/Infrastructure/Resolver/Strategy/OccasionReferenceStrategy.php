<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Resolver\Strategy;

use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Infrastructure\Repository\OccasionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class OccasionReferenceStrategy
{
    /** @var array<string,int> */
    private array $occasionIdByKey = [];

    public function __construct(
        private readonly OccasionRepository $occasionRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function warm(): void
    {
        if ([] !== $this->occasionIdByKey) {
            return;
        }

        foreach ($this->occasionRepo->findBy([], ['name' => 'ASC']) as $occasion) {
            $id = $occasion->getId();
            if (null === $id) {
                throw new \DomainException('Occasion id must not be null.');
            }

            $key = $this->key((string) $occasion->getName());
            $this->occasionIdByKey[$key] = $id;
        }
    }

    /**
     * @param object $entity must expose setOccasion()
     */
    public function apply(object $entity, ?string $occasionName): void
    {
        $key = $this->key((string) $occasionName);
        if ('' === $key) {
            return;
        }

        $occasionId = $this->occasionIdByKey[$key] ?? null;
        if (null === $occasionId) {
            // current resolvers intentionally "return early" on unknown keys
            return;
        }

        /** @var Occasion $occasionRef */
        $occasionRef = $this->em->getReference(Occasion::class, $occasionId);
        $entity->setOccasion($occasionRef);
    }

    private function key(string $name): string
    {
        $s = \mb_strtolower(\trim($name), 'UTF-8');
        $normalized = \preg_replace('/\s+/', ' ', $s);

        return $normalized ?? $s;
    }
}
