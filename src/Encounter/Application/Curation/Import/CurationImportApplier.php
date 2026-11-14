<?php

declare(strict_types=1);

namespace App\Encounter\Application\Curation\Import;

use App\Encounter\Application\Catalog\MtsCatalog;
use App\Encounter\Application\Contract\MtsCatalogRepositoryInterface;
use App\Encounter\Application\Contract\PlausibilityRepositoryInterface;
use App\Encounter\Application\Curation\Package\AreaRuleData;
use App\Encounter\Application\Curation\Package\CatalogEntryData;
use App\Encounter\Application\Curation\Package\CurationPackage;
use App\Encounter\Application\Plausibility\PlausibilityCuration;
use App\Encounter\Application\Plausibility\PlausibilityLookup;
use App\Encounter\Domain\Entity\MtsAlias;
use App\Encounter\Domain\Entity\MtsCatalogItem;
use App\Encounter\Domain\Entity\MtsDiagram;
use App\Encounter\Domain\Entity\MtsDiagramIndicator;
use App\Encounter\Domain\Entity\MtsIndicator;
use App\Encounter\Domain\Entity\PlausibilityAreaDiagram;
use App\Encounter\Domain\Entity\PlausibilityProblemArea;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use App\Encounter\Domain\Enum\MtsCatalogSource;
use App\Encounter\Domain\Enum\MtsCatalogStatus;
use App\Shared\Infrastructure\Audit\AuditContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Adds the new rows of an import plan in one database transaction (#1198). Every addition is flushed under its own
 * audit intent `encounter.curation.*` with the package checksum, source environment and export time. Existing rows are
 * never changed: entries keep their names, areas keep their PZC codes and rules.
 *
 * Entries and aliases are created with the code from the package, not derived again from a name that may have been
 * corrected since (the code never changes, ADR 032). When areas changed, the seed draft is rebuilt once from all
 * areas (created when there is none); the active snapshot never changes and activation stays a manual step (ADR 033).
 */
final readonly class CurationImportApplier
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MtsCatalogRepositoryInterface $catalogRepository,
        private PlausibilityRepositoryInterface $plausibilityRepository,
        private PlausibilityCuration $plausibilityCuration,
        private AuditContext $auditContext,
        private MtsCatalog $catalog,
        private PlausibilityLookup $lookup,
    ) {
    }

    public function apply(CurationImportPlan $plan, CurationPackage $package): void
    {
        if ($plan->hasConflicts()) {
            throw new \LogicException('An import plan with conflicts cannot be applied.');
        }

        if (0 === $plan->additionCount()) {
            return;
        }

        $context = [
            'package_checksum' => $package->meta->checksum->value,
            'source_environment' => $package->meta->sourceEnvironment,
            'exported_at' => $package->meta->exportedAt,
        ];

        try {
            $this->entityManager->wrapInTransaction(function () use ($plan, $context): void {
                foreach ($plan->newDiagrams as $entry) {
                    $this->createEntry(new MtsDiagram($entry->code, $entry->germanName, MtsCatalogSource::from($entry->source), MtsCatalogStatus::Curated), $entry, $context);
                }

                foreach ($plan->newIndicators as $entry) {
                    $this->createEntry(new MtsIndicator($entry->code, $entry->germanName, MtsCatalogSource::from($entry->source), MtsCatalogStatus::Curated), $entry, $context);
                }

                foreach ($plan->newAliases as $data) {
                    $alias = new MtsAlias(MtsCatalogKind::from($data->kind), $data->code, $data->label, $data->targetCode);
                    $this->activeEntry($alias->getKind(), $data->targetCode);
                    $this->entityManager->persist($alias);
                    $this->flush('encounter.curation.alias_created', $context + ['kind' => $data->kind, 'code' => $data->code, 'target' => $data->targetCode]);
                }

                foreach ($plan->newAssignments as $data) {
                    $diagram = $this->activeEntry(MtsCatalogKind::Diagram, $data->diagram);
                    $indicator = $this->activeEntry(MtsCatalogKind::Indicator, $data->indicator);
                    \assert($diagram instanceof MtsDiagram && $indicator instanceof MtsIndicator);
                    $this->entityManager->persist(new MtsDiagramIndicator($diagram, $indicator));
                    $this->flush('encounter.curation.assignment_created', $context + ['diagram' => $data->diagram, 'indicator' => $data->indicator]);
                }

                foreach ($plan->newAreas as $data) {
                    $area = new PlausibilityProblemArea($data->code, $data->germanName, $data->englishName);
                    $area->replaceCodes($data->pzc);
                    $area->replaceDiagrams($this->rules($data->diagrams));
                    $this->entityManager->persist($area);
                    $this->flush('encounter.curation.area_created', $context + ['code' => $data->code, 'pzc' => $area->pzcCodes(), 'diagrams' => $area->diagramPoints()]);
                }

                foreach ($plan->extensions as $extension) {
                    $this->extend($extension, $context);
                }

                if ($plan->changesAreas()) {
                    $this->auditContext->beginIntent('encounter.curation.seed_draft_rebuilt', $context);
                    try {
                        $this->plausibilityCuration->rebuildSeedDraft();
                    } finally {
                        $this->auditContext->endIntent();
                    }
                }
            });
        } finally {
            $this->catalog->reset();
            $this->lookup->reset();
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function createEntry(MtsCatalogItem $item, CatalogEntryData $entry, array $context): void
    {
        $item->setEnglishName($entry->englishName);
        $this->entityManager->persist($item);
        $this->flush('encounter.curation.entry_created', $context + ['kind' => $item->getKind()->value, 'code' => $entry->code]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function extend(AreaExtension $extension, array $context): void
    {
        $area = $this->plausibilityRepository->findAreaByCode($extension->code);
        if (!$area instanceof PlausibilityProblemArea) {
            throw new \LogicException(\sprintf('The problem area "%s" disappeared during the import.', $extension->code));
        }

        $area->replaceCodes([...$area->pzcCodes(), ...$extension->pzc]);
        $area->replaceDiagrams([
            ...array_map(static fn (PlausibilityAreaDiagram $rule): array => [$rule->getDiagram(), $rule->getPoints()], $area->diagrams()),
            ...$this->rules($extension->rules),
        ]);

        $this->flush('encounter.curation.area_extended', $context + [
            'code' => $extension->code,
            'added_pzc' => $extension->pzc,
            'added_diagrams' => array_combine(
                array_map(static fn (AreaRuleData $rule): string => $rule->code, $extension->rules),
                array_map(static fn (AreaRuleData $rule): int => $rule->points, $extension->rules),
            ),
        ]);
    }

    /**
     * @param list<AreaRuleData> $rules
     *
     * @return list<array{0: MtsDiagram, 1: int}>
     */
    private function rules(array $rules): array
    {
        return array_map(function (AreaRuleData $rule): array {
            $diagram = $this->activeEntry(MtsCatalogKind::Diagram, $rule->code);
            \assert($diagram instanceof MtsDiagram);

            return [$diagram, $rule->points];
        }, $rules);
    }

    private function activeEntry(MtsCatalogKind $kind, string $code): MtsCatalogItem
    {
        $entry = $this->catalogRepository->findEntry($kind, $code);
        if (!$entry instanceof MtsCatalogItem || !$entry->isActive()) {
            throw new \LogicException(\sprintf('The %s "%s" is not an active entry on this instance.', $kind->value, $code));
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function flush(string $intent, array $metadata): void
    {
        $this->auditContext->beginIntent($intent, $metadata);
        try {
            $this->entityManager->flush();
        } finally {
            $this->auditContext->endIntent();
        }
    }
}
