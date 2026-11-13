<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Application\Plausibility;

use App\Encounter\Application\Contract\PlausibilityRepositoryInterface;
use App\Encounter\Application\Plausibility\PlausibilityCuration;
use App\Encounter\Application\Plausibility\PlausibilityException;
use App\Encounter\Application\Plausibility\PlausibilityLookup;
use App\Encounter\Application\Plausibility\PlausibilitySnapshots;
use App\Encounter\Domain\Entity\PlausibilityProblemArea;
use App\Encounter\Domain\Entity\PlausibilitySnapshot;
use App\Encounter\Domain\Enum\PlausibilitySnapshotStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Snapshot life cycle (ADR 033): at most one active snapshot, activation retires the previous one, retiring leaves
 * none (bonus 0), only drafts are deleted, every change audited.
 */
#[ResetDatabase]
final class PlausibilitySnapshotsTest extends KernelTestCase
{
    private PlausibilitySnapshots $snapshots;

    private PlausibilityRepositoryInterface $repository;

    private PlausibilityLookup $lookup;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->snapshots = self::getContainer()->get(PlausibilitySnapshots::class);
        $this->repository = self::getContainer()->get(PlausibilityRepositoryInterface::class);
        $this->lookup = self::getContainer()->get(PlausibilityLookup::class);
    }

    public function testActivatingADraftRetiresTheActiveSnapshot(): void
    {
        $seed = $this->seedDraft();
        $this->snapshots->activate($seed);
        self::assertSame(PlausibilitySnapshotStatus::Active, $seed->getStatus());
        self::assertSame(3, $this->lookup->bonus(711, 'urologisches_problem')->points);
        self::assertSame($seed->getId(), $this->lookup->bonus(711, 'urologisches_problem')->snapshotId);

        $this->editUrology(1);
        $second = $this->seedDraft();
        $this->snapshots->activate($second);

        self::assertSame(PlausibilitySnapshotStatus::Retired, $seed->getStatus());
        self::assertSame(PlausibilitySnapshotStatus::Active, $second->getStatus());
        self::assertSame($second->getId(), $this->repository->activeSnapshot()?->getId());
        self::assertSame(1, $this->lookup->bonus(711, 'urologisches_problem')->points, 'the lookup reads the new snapshot');
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM encounter_plausibility_snapshot WHERE status = 'active'"));
        self::assertSame(3, $this->auditEntries('encounter.plausibility.snapshot_activated'), 'seed activated, seed retired, second activated');
    }

    public function testTheDatabaseAllowsOneActiveSnapshotOnly(): void
    {
        $this->snapshots->activate($this->seedDraft());
        $this->editUrology(2);

        $this->expectException(UniqueConstraintViolationException::class);
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE encounter_plausibility_snapshot SET status = 'active' WHERE status = 'draft'");
    }

    public function testRetiringLeavesNoActiveSnapshot(): void
    {
        $seed = $this->seedDraft();
        $this->snapshots->activate($seed);
        $this->snapshots->retire($seed);

        self::assertNull($this->repository->activeSnapshot());
        self::assertSame(0, $this->lookup->bonus(711, 'urologisches_problem')->points);
        self::assertSame(1, $this->auditEntries('encounter.plausibility.snapshot_retired'));
        self::assertSame(PlausibilityException::SNAPSHOT_NOT_DRAFT, $this->refusal(fn () => $this->snapshots->activate($seed))->reason, 'a retired snapshot stays retired');
        self::assertSame(PlausibilityException::SNAPSHOT_NOT_ACTIVE, $this->refusal(fn () => $this->snapshots->retire($seed))->reason);
    }

    public function testOnlyDraftsAreDeleted(): void
    {
        $seed = $this->seedDraft();
        $this->snapshots->activate($seed);
        self::assertSame(PlausibilityException::SNAPSHOT_NOT_DRAFT, $this->refusal(fn () => $this->snapshots->deleteDraft($seed))->reason);

        $this->editUrology(2);
        $draft = $this->seedDraft();
        $draftId = $draft->getId();
        $this->snapshots->deleteDraft($draft);

        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM encounter_plausibility_cell WHERE snapshot_id = '.(int) $draftId));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM encounter_plausibility_snapshot_code WHERE snapshot_id = '.(int) $draftId));
        self::assertCount(1, $this->repository->snapshots());
        self::assertSame(1, $this->auditEntries('encounter.plausibility.snapshot_deleted'));
    }

    private function editUrology(int $points): void
    {
        $urology = $this->repository->findAreaByCode('urologie');
        self::assertInstanceOf(PlausibilityProblemArea::class, $urology);
        self::getContainer()->get(PlausibilityCuration::class)->updateArea($urology, 'Urologie', 'Urology', $urology->pzcCodes(), ['urologisches_problem' => $points]);
    }

    private function seedDraft(): PlausibilitySnapshot
    {
        $draft = $this->repository->seedDraft();
        self::assertInstanceOf(PlausibilitySnapshot::class, $draft);

        return $draft;
    }

    private function refusal(callable $change): PlausibilityException
    {
        try {
            $change();
        } catch (PlausibilityException $exception) {
            return $exception;
        }

        self::fail('expected a refusal');
    }

    private function countRows(string $sql): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne($sql);
    }

    private function auditEntries(string $intent): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE intent = ? AND entity_class = ?',
            [$intent, PlausibilitySnapshot::class],
        );
    }
}
