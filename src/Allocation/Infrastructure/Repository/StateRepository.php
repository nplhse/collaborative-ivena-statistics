<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Application\Contracts\StateLookupInterface;
use App\Allocation\Domain\Entity\State;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<State>
 */
final class StateRepository extends ServiceEntityRepository implements StateLookupInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, State::class);
    }

    #[\Override]
    public function findById(int $id): ?State
    {
        $entity = $this->find($id);

        return $entity instanceof State ? $entity : null;
    }
}
