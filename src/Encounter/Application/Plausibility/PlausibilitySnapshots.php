<?php

declare(strict_types=1);

namespace App\Encounter\Application\Plausibility;

use App\Encounter\Application\Contract\PlausibilityRepositoryInterface;
use App\Encounter\Domain\Entity\PlausibilitySnapshot;
use App\Shared\Infrastructure\Audit\AuditContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Activation, retirement and deletion of plausibility snapshots by administrators and catalog reviewers (ADR 033).
 *
 * - Activating a draft retires the active snapshot, in one transaction; at most one snapshot is active.
 * - Retiring the active snapshot leaves none active: the bonus is 0 everywhere.
 * - Only drafts can be deleted; active and retired snapshots stay so every scored pair remains reproducible.
 *
 * Each change is audited (`encounter.plausibility.snapshot_*`). Links and manual decisions are never touched; open
 * proposals are recomputed by the next matcher run over their allocations (#1154).
 */
final readonly class PlausibilitySnapshots
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlausibilityRepositoryInterface $repository,
        private AuditContext $auditContext,
        private ClockInterface $clock,
        private PlausibilityLookup $lookup,
    ) {
    }

    /**
     * @throws PlausibilityException
     */
    public function activate(PlausibilitySnapshot $snapshot): void
    {
        if (!$snapshot->isDraft()) {
            throw new PlausibilityException(PlausibilityException::SNAPSHOT_NOT_DRAFT, ['id' => (int) $snapshot->getId()]);
        }

        $previous = $this->repository->activeSnapshot();
        $this->audited('encounter.plausibility.snapshot_activated', ['snapshot' => $snapshot->getId(), 'retired' => $previous?->getId()], function () use ($snapshot, $previous): void {
            $now = $this->clock->now();
            if ($previous instanceof PlausibilitySnapshot) {
                $previous->retire($now);
                $this->entityManager->flush();
            }

            $snapshot->activate($now);
            $this->entityManager->flush();
        });
    }

    /**
     * @throws PlausibilityException
     */
    public function retire(PlausibilitySnapshot $snapshot): void
    {
        if (!$snapshot->isActive()) {
            throw new PlausibilityException(PlausibilityException::SNAPSHOT_NOT_ACTIVE, ['id' => (int) $snapshot->getId()]);
        }

        $this->audited('encounter.plausibility.snapshot_retired', ['snapshot' => $snapshot->getId()], function () use ($snapshot): void {
            $snapshot->retire($this->clock->now());
            $this->entityManager->flush();
        });
    }

    /**
     * @throws PlausibilityException
     */
    public function deleteDraft(PlausibilitySnapshot $snapshot): void
    {
        if (!$snapshot->isDraft()) {
            throw new PlausibilityException(PlausibilityException::SNAPSHOT_NOT_DRAFT, ['id' => (int) $snapshot->getId()]);
        }

        $this->audited('encounter.plausibility.snapshot_deleted', ['snapshot' => $snapshot->getId(), 'origin' => $snapshot->getOrigin()->value], function () use ($snapshot): void {
            $this->entityManager->remove($snapshot);
            $this->entityManager->flush();
        });
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audited(string $intent, array $metadata, \Closure $change): void
    {
        $this->auditContext->beginIntent($intent, $metadata);
        try {
            $this->entityManager->wrapInTransaction($change);
        } finally {
            $this->auditContext->endIntent();
            $this->lookup->reset();
        }
    }
}
