<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Repository;

use App\Encounter\Application\Contract\MtsCatalogRepositoryInterface;
use App\Encounter\Domain\Entity\MtsAlias;
use App\Encounter\Domain\Entity\MtsCatalogItem;
use App\Encounter\Domain\Entity\MtsDiagram;
use App\Encounter\Domain\Entity\MtsDiagramIndicator;
use App\Encounter\Domain\Entity\MtsIndicator;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use App\Encounter\Domain\Enum\MtsCatalogStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lookups over the MTS catalog entities (diagram, indicator, alias, assignment; ADR 032).
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of MtsCatalogRepositoryInterface.
 */
final readonly class MtsCatalogRepository implements MtsCatalogRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function findEntry(MtsCatalogKind $kind, string $code): ?MtsCatalogItem
    {
        return $this->entityManager->getRepository($this->entityClass($kind))->findOneBy(['code' => $code]);
    }

    #[\Override]
    public function findAlias(MtsCatalogKind $kind, string $code): ?MtsAlias
    {
        return $this->entityManager->getRepository(MtsAlias::class)->findOneBy(['kind' => $kind, 'code' => $code]);
    }

    #[\Override]
    public function countAliasesTargeting(MtsCatalogKind $kind, string $code): int
    {
        return $this->entityManager->getRepository(MtsAlias::class)->count(['kind' => $kind, 'targetCode' => $code]);
    }

    #[\Override]
    public function countAssignments(MtsCatalogItem $entry): int
    {
        $field = $entry instanceof MtsDiagram ? 'diagram' : 'indicator';

        return $this->entityManager->getRepository(MtsDiagramIndicator::class)->count([$field => $entry]);
    }

    #[\Override]
    public function findAssignment(MtsDiagram $diagram, MtsIndicator $indicator): ?MtsDiagramIndicator
    {
        return $this->entityManager->getRepository(MtsDiagramIndicator::class)->findOneBy(['diagram' => $diagram, 'indicator' => $indicator]);
    }

    #[\Override]
    public function activeEntries(MtsCatalogKind $kind): array
    {
        /** @var list<MtsCatalogItem> $entries */
        $entries = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($this->entityClass($kind), 'e')
            ->andWhere('e.status <> :merged')
            ->setParameter('merged', MtsCatalogStatus::Merged)
            ->orderBy('e.code', 'ASC')
            ->getQuery()
            ->getResult();

        return $entries;
    }

    /**
     * @return class-string<MtsDiagram>|class-string<MtsIndicator>
     */
    private function entityClass(MtsCatalogKind $kind): string
    {
        return MtsCatalogKind::Diagram === $kind ? MtsDiagram::class : MtsIndicator::class;
    }
}
