<?php

declare(strict_types=1);

namespace App\Encounter\Application\Catalog;

use App\Encounter\Application\Contract\MtsCatalogRepositoryInterface;
use App\Encounter\Domain\Entity\MtsAlias;
use App\Encounter\Domain\Entity\MtsCatalogItem;
use App\Encounter\Domain\Entity\MtsDiagram;
use App\Encounter\Domain\Entity\MtsDiagramIndicator;
use App\Encounter\Domain\Entity\MtsIndicator;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use App\Encounter\Domain\Enum\MtsCatalogStatus;
use App\Shared\Infrastructure\Audit\AuditContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maintenance of the MTS catalog by administrators (ADR 032). Every change is flushed under an audit intent
 * `encounter.mts_catalog.*`; the entities are audited.
 *
 * - Codes are derived once from the German name or alias label (MtsCatalog::code()) and are unique per kind across
 *   entries and aliases. Creating an entry or alias whose code exists is refused: as a duplicate when the existing
 *   label is the same, as a collision when it is another label (the exchange format cannot tell them apart).
 * - Aliases point to an active entry of the same kind, so resolution takes one step.
 * - Merge: a provisional entry becomes `merged` and an alias from its code to the target is created, in one
 *   transaction. Refused while the entry is an alias target or has diagram–indicator assignments.
 * - Undo: the alias is removed and the entry is `provisional` again, in one transaction.
 *
 * Encounter rows are never touched: they keep the code as sent and readers resolve it.
 */
final readonly class MtsCatalogCuration
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MtsCatalogRepositoryInterface $repository,
        private AuditContext $auditContext,
        private MtsCatalog $catalog,
    ) {
    }

    /**
     * The duplicate or collision a new entry or alias with this label would cause, or null.
     */
    public function conflictFor(MtsCatalogKind $kind, string $label): ?MtsCatalogException
    {
        $code = MtsCatalog::code($label);
        if ('' === $code) {
            return new MtsCatalogException(MtsCatalogException::EMPTY_CODE, ['label' => $label]);
        }

        $entry = $this->repository->findEntry($kind, $code);
        if ($entry instanceof MtsCatalogItem) {
            return $this->codeTaken($code, $label, $entry->getGermanName(), $entry->getCode());
        }

        $alias = $this->repository->findAlias($kind, $code);
        if ($alias instanceof MtsAlias) {
            return $this->codeTaken($code, $label, $alias->getLabel(), $alias->getTargetCode());
        }

        return null;
    }

    /**
     * @throws MtsCatalogException
     */
    public function createEntry(MtsCatalogItem $entry): void
    {
        $this->throwIf($this->conflictFor($entry->getKind(), $entry->getGermanName()));
        $entry->assignCode(MtsCatalog::code($entry->getGermanName()));

        $this->entityManager->persist($entry);
        $this->flush('encounter.mts_catalog.entry_created', $this->entryMetadata($entry));
    }

    public function updateEntry(MtsCatalogItem $entry): void
    {
        $this->flush('encounter.mts_catalog.entry_updated', $this->entryMetadata($entry));
    }

    /**
     * @throws MtsCatalogException
     */
    public function createAlias(MtsAlias $alias): void
    {
        $this->throwIf($this->conflictFor($alias->getKind(), $alias->getLabel()));
        $alias->assignCode(MtsCatalog::code($alias->getLabel()));
        $this->assertActiveTarget($alias->getKind(), $alias->getTargetCode());

        $this->entityManager->persist($alias);
        $this->flush('encounter.mts_catalog.alias_created', $this->aliasMetadata($alias));
    }

    /**
     * Saves a changed label or target of an alias that does not stand for a merge.
     *
     * @throws MtsCatalogException
     */
    public function updateAlias(MtsAlias $alias): void
    {
        $this->assertNotMergeAlias($alias);
        $this->assertActiveTarget($alias->getKind(), $alias->getTargetCode());

        $this->flush('encounter.mts_catalog.alias_updated', $this->aliasMetadata($alias));
    }

    /**
     * @throws MtsCatalogException
     */
    public function deleteAlias(MtsAlias $alias): void
    {
        $this->assertNotMergeAlias($alias);
        $metadata = $this->aliasMetadata($alias);

        $this->entityManager->remove($alias);
        $this->flush('encounter.mts_catalog.alias_deleted', $metadata);
    }

    /**
     * @throws MtsCatalogException
     */
    public function assign(MtsDiagramIndicator $assignment): void
    {
        $diagram = $assignment->getDiagram();
        $indicator = $assignment->getIndicator();
        if (!$diagram instanceof MtsDiagram || !$indicator instanceof MtsIndicator || !$diagram->isActive() || !$indicator->isActive()) {
            throw new MtsCatalogException(MtsCatalogException::ASSIGNMENT_NOT_ACTIVE);
        }

        if ($this->repository->findAssignment($diagram, $indicator) instanceof MtsDiagramIndicator) {
            throw new MtsCatalogException(MtsCatalogException::ASSIGNMENT_EXISTS, ['diagram' => $diagram->getCode(), 'indicator' => $indicator->getCode()]);
        }

        $this->entityManager->persist($assignment);
        $this->flush('encounter.mts_catalog.assignment_created', ['diagram' => $diagram->getCode(), 'indicator' => $indicator->getCode()]);
    }

    public function assignmentExists(MtsDiagramIndicator $assignment): bool
    {
        $diagram = $assignment->getDiagram();
        $indicator = $assignment->getIndicator();

        return $diagram instanceof MtsDiagram && $indicator instanceof MtsIndicator
            && $this->repository->findAssignment($diagram, $indicator) instanceof MtsDiagramIndicator;
    }

    public function unassign(MtsDiagramIndicator $assignment): void
    {
        $metadata = ['diagram' => $assignment->getDiagram()?->getCode(), 'indicator' => $assignment->getIndicator()?->getCode()];

        $this->entityManager->remove($assignment);
        $this->flush('encounter.mts_catalog.assignment_deleted', $metadata);
    }

    /**
     * Whether merge() would accept the entry (provisional, no aliases point to it, no assignments).
     */
    public function mergeBlocker(MtsCatalogItem $entry): ?MtsCatalogException
    {
        if (MtsCatalogStatus::Provisional !== $entry->getStatus()) {
            return new MtsCatalogException(MtsCatalogException::MERGE_NOT_PROVISIONAL, ['code' => $entry->getCode()]);
        }

        $aliases = $this->repository->countAliasesTargeting($entry->getKind(), $entry->getCode());
        if ($aliases > 0) {
            return new MtsCatalogException(MtsCatalogException::MERGE_HAS_ALIASES, ['code' => $entry->getCode(), 'count' => $aliases]);
        }

        $assignments = $this->repository->countAssignments($entry);
        if ($assignments > 0) {
            return new MtsCatalogException(MtsCatalogException::MERGE_HAS_ASSIGNMENTS, ['code' => $entry->getCode(), 'count' => $assignments]);
        }

        return null;
    }

    /**
     * Merges a provisional entry into an active entry of the same kind (ADR 032).
     *
     * @throws MtsCatalogException
     */
    public function merge(MtsCatalogItem $entry, MtsCatalogItem $target): void
    {
        $this->throwIf($this->mergeBlocker($entry));
        if ($entry === $target || ($entry->getKind() === $target->getKind() && $entry->getCode() === $target->getCode())) {
            throw new MtsCatalogException(MtsCatalogException::MERGE_INTO_ITSELF, ['code' => $entry->getCode()]);
        }

        if ($entry->getKind() !== $target->getKind() || !$target->isActive()) {
            throw new MtsCatalogException(MtsCatalogException::TARGET_NOT_ACTIVE, ['code' => $target->getCode()]);
        }

        $alias = new MtsAlias($entry->getKind(), $entry->getCode(), $entry->getGermanName(), $target->getCode());
        $this->entityManager->wrapInTransaction(function () use ($entry, $alias, $target): void {
            $entry->markMerged();
            $this->entityManager->persist($alias);
            $this->flush('encounter.mts_catalog.merged', $this->entryMetadata($entry) + ['target' => $target->getCode()]);
        });
    }

    /**
     * Undoes the merge of an entry: its alias is removed and it is provisional again (ADR 032).
     *
     * @throws MtsCatalogException
     */
    public function undoMerge(MtsCatalogItem $entry): void
    {
        if (MtsCatalogStatus::Merged !== $entry->getStatus()) {
            throw new MtsCatalogException(MtsCatalogException::NOT_MERGED, ['code' => $entry->getCode()]);
        }

        $alias = $this->repository->findAlias($entry->getKind(), $entry->getCode());
        $this->entityManager->wrapInTransaction(function () use ($entry, $alias): void {
            $target = null;
            if ($alias instanceof MtsAlias) {
                $target = $alias->getTargetCode();
                $this->entityManager->remove($alias);
            }

            $entry->undoMerge();
            $this->flush('encounter.mts_catalog.merge_undone', $this->entryMetadata($entry) + ['target' => $target]);
        });
    }

    /**
     * Whether the alias stands for a merge: its code is the code of a merged entry of the same kind.
     */
    public function isMergeAlias(MtsAlias $alias): bool
    {
        $entry = $this->repository->findEntry($alias->getKind(), $alias->getCode());

        return $entry instanceof MtsCatalogItem && MtsCatalogStatus::Merged === $entry->getStatus();
    }

    /**
     * @return list<MtsCatalogItem>
     */
    public function mergeTargets(MtsCatalogItem $entry): array
    {
        return array_values(array_filter(
            $this->repository->activeEntries($entry->getKind()),
            static fn (MtsCatalogItem $target): bool => $target->getCode() !== $entry->getCode(),
        ));
    }

    private function codeTaken(string $code, string $label, string $existingLabel, string $resolvesTo): MtsCatalogException
    {
        $sameLabel = mb_strtolower(trim($label)) === mb_strtolower(trim($existingLabel));

        return new MtsCatalogException(
            $sameLabel ? MtsCatalogException::DUPLICATE : MtsCatalogException::COLLISION,
            ['code' => $code, 'label' => $label, 'existing' => $existingLabel, 'target' => $resolvesTo],
        );
    }

    /**
     * @throws MtsCatalogException
     */
    private function assertActiveTarget(MtsCatalogKind $kind, string $code): void
    {
        $target = $this->repository->findEntry($kind, $code);
        if (!$target instanceof MtsCatalogItem || !$target->isActive()) {
            throw new MtsCatalogException(MtsCatalogException::TARGET_NOT_ACTIVE, ['code' => $code]);
        }
    }

    /**
     * @throws MtsCatalogException
     */
    private function assertNotMergeAlias(MtsAlias $alias): void
    {
        if ($this->isMergeAlias($alias)) {
            throw new MtsCatalogException(MtsCatalogException::MERGE_ALIAS, ['code' => $alias->getCode()]);
        }
    }

    /**
     * @throws MtsCatalogException
     */
    private function throwIf(?MtsCatalogException $exception): void
    {
        if ($exception instanceof MtsCatalogException) {
            throw $exception;
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function entryMetadata(MtsCatalogItem $entry): array
    {
        return ['kind' => $entry->getKind()->value, 'code' => $entry->getCode(), 'status' => $entry->getStatus()->value];
    }

    /**
     * @return array<string, string>
     */
    private function aliasMetadata(MtsAlias $alias): array
    {
        return ['kind' => $alias->getKind()->value, 'code' => $alias->getCode(), 'target' => $alias->getTargetCode()];
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
            $this->catalog->reset();
        }
    }
}
