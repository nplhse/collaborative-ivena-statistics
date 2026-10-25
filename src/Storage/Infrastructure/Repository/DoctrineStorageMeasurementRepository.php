<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Repository;

use App\Storage\Application\Contract\StorageMeasurementRepositoryInterface;
use App\Storage\Domain\Entity\StorageMeasurement;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for StorageMeasurementRepositoryInterface.
 */
#[AsAlias(StorageMeasurementRepositoryInterface::class)]
final readonly class DoctrineStorageMeasurementRepository implements StorageMeasurementRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function save(StorageMeasurement $measurement): StorageMeasurement
    {
        $existing = $this->findForDay($measurement->getMeasuredOn());

        if ($existing instanceof StorageMeasurement && $existing !== $measurement) {
            $existing->replaceWith($measurement);
            $measurement = $existing;
        } else {
            $this->entityManager->persist($measurement);
        }

        $this->entityManager->flush();

        return $measurement;
    }

    #[\Override]
    public function latest(): ?StorageMeasurement
    {
        return $this->entities()->findOneBy([], ['measuredOn' => 'DESC']);
    }

    #[\Override]
    public function findForDay(\DateTimeImmutable $day): ?StorageMeasurement
    {
        return $this->entities()->findOneBy(['measuredOn' => $day->setTime(0, 0)]);
    }

    #[\Override]
    public function findSince(\DateTimeImmutable $day): array
    {
        $criteria = Criteria::create()
            ->where(Criteria::expr()->gte('measuredOn', $day->setTime(0, 0)))
            ->orderBy(['measuredOn' => Order::Ascending]);

        return array_values($this->entities()->matching($criteria)->toArray());
    }

    /**
     * @return EntityRepository<StorageMeasurement>
     */
    private function entities(): EntityRepository
    {
        return $this->entityManager->getRepository(StorageMeasurement::class);
    }
}
