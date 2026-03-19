<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Resolver\Strategy;

use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Infrastructure\Repository\InfectionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class InfectionReferenceStrategy
{
    /** @var array<string,int> */
    private array $infectionIdByKey = [];

    public function __construct(
        private readonly InfectionRepository $infectionRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function warm(): void
    {
        if ([] !== $this->infectionIdByKey) {
            return;
        }

        foreach ($this->infectionRepo->findBy([], ['name' => 'ASC']) as $infection) {
            $id = $infection->getId();
            if (null === $id) {
                throw new \DomainException('Infection id must not be null.');
            }

            $key = $this->key((string) $infection->getName());
            $this->infectionIdByKey[$key] = $id;
        }
    }

    /**
     * @param object $entity must expose setInfection()
     */
    public function apply(object $entity, ?string $infectionName): void
    {
        $key = $this->key((string) $infectionName);
        if ('' === $key) {
            return;
        }

        $infectionId = $this->infectionIdByKey[$key] ?? null;
        if (null === $infectionId) {
            // current resolvers intentionally "return early" on unknown keys
            return;
        }

        /** @var Infection $infectionRef */
        $infectionRef = $this->em->getReference(Infection::class, $infectionId);
        $entity->setInfection($infectionRef);
    }

    private function key(string $name): string
    {
        $s = \mb_strtolower(\trim($name), 'UTF-8');
        $normalized = \preg_replace('/\s+/', ' ', $s);

        return $normalized ?? $s;
    }
}
