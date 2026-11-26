<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Infrastructure\Repository;

use Cois\Plugin\EdVisit\Application\Contract\PlausibilityRepositoryInterface;
use Cois\Plugin\EdVisit\Domain\Entity\PlausibilityAreaCode;
use Cois\Plugin\EdVisit\Domain\Entity\PlausibilityProblemArea;
use Cois\Plugin\EdVisit\Domain\Entity\PlausibilitySnapshot;
use Cois\Plugin\EdVisit\Domain\Enum\PlausibilitySnapshotOrigin;
use Cois\Plugin\EdVisit\Domain\Enum\PlausibilitySnapshotStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lookups over the curated problem areas and the snapshots of the plausibility table (ADR 033).
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of PlausibilityRepositoryInterface.
 */
final readonly class PlausibilityRepository implements PlausibilityRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function areas(): array
    {
        return $this->entityManager->getRepository(PlausibilityProblemArea::class)->findBy([], ['germanName' => 'ASC']);
    }

    #[\Override]
    public function findAreaByCode(string $code): ?PlausibilityProblemArea
    {
        return $this->entityManager->getRepository(PlausibilityProblemArea::class)->findOneBy(['code' => $code]);
    }

    #[\Override]
    public function areaOfPzc(int $pzc): ?PlausibilityProblemArea
    {
        return $this->entityManager->getRepository(PlausibilityAreaCode::class)->findOneBy(['pzc' => $pzc])?->getArea();
    }

    #[\Override]
    public function snapshots(): array
    {
        return $this->entityManager->getRepository(PlausibilitySnapshot::class)->findBy([], ['id' => 'DESC']);
    }

    #[\Override]
    public function activeSnapshot(): ?PlausibilitySnapshot
    {
        return $this->entityManager->getRepository(PlausibilitySnapshot::class)->findOneBy(['status' => PlausibilitySnapshotStatus::Active]);
    }

    #[\Override]
    public function seedDraft(): ?PlausibilitySnapshot
    {
        return $this->entityManager->getRepository(PlausibilitySnapshot::class)->findOneBy(
            ['status' => PlausibilitySnapshotStatus::Draft, 'origin' => PlausibilitySnapshotOrigin::Seed],
            ['id' => 'DESC'],
        );
    }
}
