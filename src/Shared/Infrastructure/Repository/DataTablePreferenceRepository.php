<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Repository;

use App\Shared\Domain\Entity\DataTablePreference;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DataTablePreference>
 */
final class DataTablePreferenceRepository extends ServiceEntityRepository
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DataTablePreference::class);
    }

    public function findForUserAndTable(User $user, string $tableKey): ?DataTablePreference
    {
        return $this->findOneBy(['user' => $user, 'tableKey' => $tableKey]);
    }

    public function save(DataTablePreference $preference): void
    {
        $this->getEntityManager()->persist($preference);
        $this->getEntityManager()->flush();
    }

    public function remove(DataTablePreference $preference): void
    {
        $this->getEntityManager()->remove($preference);
        $this->getEntityManager()->flush();
    }
}
