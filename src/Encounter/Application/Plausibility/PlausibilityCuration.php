<?php

declare(strict_types=1);

namespace App\Encounter\Application\Plausibility;

use App\Encounter\Application\Catalog\MtsCatalog;
use App\Encounter\Application\Contract\MtsCatalogRepositoryInterface;
use App\Encounter\Application\Contract\PlausibilityRepositoryInterface;
use App\Encounter\Application\Contract\PlausibilitySnapshotBuilderInterface;
use App\Encounter\Domain\Entity\MtsDiagram;
use App\Encounter\Domain\Entity\PlausibilityCell;
use App\Encounter\Domain\Entity\PlausibilityProblemArea;
use App\Encounter\Domain\Entity\PlausibilitySnapshot;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use App\Encounter\Domain\Enum\PlausibilitySnapshotOrigin;
use App\Shared\Infrastructure\Audit\AuditContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Editing of the curated problem areas of the plausibility table by administrators and catalog reviewers (ADR 033).
 *
 * - The code of an area is derived once from its German name (MtsCatalog::code()) and never changes.
 * - Each PZC belongs to at most one area; a PZC of another area is refused and has to be removed there first.
 * - Rules name active MTS diagrams with points 0 … +3.
 * - Every change is flushed under an audit intent `encounter.plausibility.area_*` and rebuilds the seed draft
 *   snapshot in the same transaction (created when there is none). The active snapshot never changes.
 */
final readonly class PlausibilityCuration
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlausibilityRepositoryInterface $repository,
        private MtsCatalogRepositoryInterface $catalogRepository,
        private PlausibilitySnapshotBuilderInterface $builder,
        private AuditContext $auditContext,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<int>          $pzcCodes
     * @param array<string, int> $diagramPoints points by diagram code
     *
     * @throws PlausibilityException
     */
    public function createArea(string $germanName, ?string $englishName, array $pzcCodes, array $diagramPoints): PlausibilityProblemArea
    {
        $code = MtsCatalog::code($germanName);
        if ('' === $code) {
            throw new PlausibilityException(PlausibilityException::EMPTY_NAME, ['name' => $germanName]);
        }

        $existing = $this->repository->findAreaByCode($code);
        if ($existing instanceof PlausibilityProblemArea) {
            throw new PlausibilityException(PlausibilityException::AREA_EXISTS, ['code' => $code, 'name' => $existing->getGermanName()]);
        }

        $area = new PlausibilityProblemArea($code, $germanName, $englishName);
        $this->apply($area, $pzcCodes, $diagramPoints);
        $this->entityManager->persist($area);
        $this->save('encounter.plausibility.area_created', $area);

        return $area;
    }

    /**
     * @param list<int>          $pzcCodes
     * @param array<string, int> $diagramPoints points by diagram code
     *
     * @throws PlausibilityException
     */
    public function updateArea(PlausibilityProblemArea $area, string $germanName, ?string $englishName, array $pzcCodes, array $diagramPoints): void
    {
        if ('' === MtsCatalog::code($germanName)) {
            throw new PlausibilityException(PlausibilityException::EMPTY_NAME, ['name' => $germanName]);
        }

        $area->setGermanName($germanName);
        $area->setEnglishName($englishName);
        $this->apply($area, $pzcCodes, $diagramPoints);
        $this->save('encounter.plausibility.area_updated', $area);
    }

    public function deleteArea(PlausibilityProblemArea $area): void
    {
        $this->entityManager->remove($area);
        $this->save('encounter.plausibility.area_deleted', $area);
    }

    /**
     * @param list<int>          $pzcCodes
     * @param array<string, int> $diagramPoints
     *
     * @throws PlausibilityException
     */
    private function apply(PlausibilityProblemArea $area, array $pzcCodes, array $diagramPoints): void
    {
        foreach ($pzcCodes as $pzc) {
            if ($pzc < 0 || $pzc > 999) {
                throw new PlausibilityException(PlausibilityException::PZC_INVALID, ['pzc' => $pzc]);
            }

            $owner = $this->repository->areaOfPzc($pzc);
            if ($owner instanceof PlausibilityProblemArea && $owner !== $area) {
                throw new PlausibilityException(PlausibilityException::PZC_TAKEN, ['pzc' => \sprintf('%03d', $pzc), 'area' => $owner->getGermanName()]);
            }
        }

        $rules = [];
        foreach ($diagramPoints as $code => $points) {
            if ($points < PlausibilityCell::MIN_POINTS || $points > PlausibilityCell::MAX_POINTS) {
                throw new PlausibilityException(PlausibilityException::POINTS, ['points' => $points, 'max' => PlausibilityCell::MAX_POINTS]);
            }

            $diagram = $this->catalogRepository->findEntry(MtsCatalogKind::Diagram, (string) $code);
            if (!$diagram instanceof MtsDiagram || !$diagram->isActive()) {
                throw new PlausibilityException(PlausibilityException::DIAGRAM_NOT_ACTIVE, ['code' => (string) $code]);
            }

            $rules[] = [$diagram, $points];
        }

        $area->replaceCodes(array_values(array_unique($pzcCodes)));
        $area->replaceDiagrams($rules);
    }

    private function save(string $intent, PlausibilityProblemArea $area): void
    {
        $metadata = ['code' => $area->getCode(), 'pzc' => $area->pzcCodes(), 'diagrams' => $area->diagramPoints()];
        $this->auditContext->beginIntent($intent, $metadata);
        try {
            $this->entityManager->wrapInTransaction(function (): void {
                $this->entityManager->flush();
                $draft = $this->repository->seedDraft();
                if (!$draft instanceof PlausibilitySnapshot) {
                    $draft = new PlausibilitySnapshot(PlausibilitySnapshotOrigin::Seed, $this->clock->now());
                    $this->entityManager->persist($draft);
                    $this->entityManager->flush();
                }

                $this->builder->rebuildFromAreas($draft);
            });
        } finally {
            $this->auditContext->endIntent();
        }
    }
}
