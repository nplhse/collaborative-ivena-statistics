<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Application\Plausibility;

use App\Encounter\Application\Catalog\MtsCatalogCuration;
use App\Encounter\Application\Contract\PlausibilityRepositoryInterface;
use App\Encounter\Application\Plausibility\PlausibilityCuration;
use App\Encounter\Application\Plausibility\PlausibilityException;
use App\Encounter\Application\Plausibility\PlausibilityLevel;
use App\Encounter\Application\Plausibility\PlausibilityLookup;
use App\Encounter\Application\Plausibility\PlausibilitySnapshots;
use App\Encounter\Domain\Entity\MtsDiagram;
use App\Encounter\Domain\Entity\PlausibilityAreaCode;
use App\Encounter\Domain\Entity\PlausibilityAreaDiagram;
use App\Encounter\Domain\Entity\PlausibilityProblemArea;
use App\Encounter\Domain\Entity\PlausibilitySnapshot;
use App\Encounter\Domain\Enum\MtsCatalogSource;
use App\Encounter\Domain\Enum\MtsCatalogStatus;
use App\Encounter\Domain\Enum\PlausibilitySnapshotStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Editing the curated problem areas (ADR 033) on top of the seed: one area per PZC, rules on active diagrams with
 * 0 … +3 points, every change audited and written into the seed draft, never into the active snapshot.
 */
#[ResetDatabase]
final class PlausibilityCurationTest extends KernelTestCase
{
    private PlausibilityCuration $curation;

    private PlausibilityRepositoryInterface $repository;

    private PlausibilityLookup $lookup;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->curation = self::getContainer()->get(PlausibilityCuration::class);
        $this->repository = self::getContainer()->get(PlausibilityRepositoryInterface::class);
        $this->lookup = self::getContainer()->get(PlausibilityLookup::class);
    }

    public function testNewAreaGetsItsCodeAndGoesIntoTheSeedDraft(): void
    {
        $area = $this->curation->createArea('Augen', 'Eyes', [729, 721, 721], ['gesichtsprobleme' => 2]);

        self::assertSame('augen', $area->getCode());
        self::assertSame([721, 729], $area->pzcCodes());
        self::assertSame(['gesichtsprobleme' => 2], $area->diagramPoints());
        self::assertSame(1, $this->auditEntries('encounter.plausibility.area_created'));

        $draft = $this->seedDraft();
        self::assertCount(1, $this->repository->snapshots(), 'the seed draft was changed, no new snapshot');
        $table = $this->lookup->table((int) $draft->getId());
        self::assertSame(2, $table->lookup(721, 'gesichtsprobleme')->points);
        self::assertSame(PlausibilityLevel::Area, $table->lookup(729, 'gesichtsprobleme')->level);
    }

    public function testEachPzcBelongsToAtMostOneArea(): void
    {
        $refusal = $this->refusal(fn (): PlausibilityProblemArea => $this->curation->createArea('Nieren', null, [711], []));

        self::assertSame(PlausibilityException::PZC_TAKEN, $refusal->reason);
        self::assertSame(['pzc' => '711', 'area' => 'Urologie'], $refusal->parameters());
        self::assertNull($this->repository->findAreaByCode('nieren'));
    }

    public function testRulesNeedAnActiveDiagramAndPointsUpToThree(): void
    {
        self::assertSame(PlausibilityException::DIAGRAM_NOT_ACTIVE, $this->refusal(fn (): PlausibilityProblemArea => $this->curation->createArea('Augen', null, [721], ['augenprobleme' => 2]))->reason);
        self::assertSame(PlausibilityException::POINTS, $this->refusal(fn (): PlausibilityProblemArea => $this->curation->createArea('Augen', null, [721], ['gesichtsprobleme' => 4]))->reason);
        self::assertSame(PlausibilityException::AREA_EXISTS, $this->refusal(fn (): PlausibilityProblemArea => $this->curation->createArea('Urologie', null, [], []))->reason);
        self::assertSame(PlausibilityException::EMPTY_NAME, $this->refusal(fn (): PlausibilityProblemArea => $this->curation->createArea(' – ', null, [], []))->reason);
    }

    public function testEditingChangesTheDraftButNeverTheActiveSnapshot(): void
    {
        $seed = $this->seedDraft();
        self::getContainer()->get(PlausibilitySnapshots::class)->activate($seed);
        self::assertSame(3, $this->lookup->bonus(711, 'urologisches_problem')->points);

        $urology = $this->repository->findAreaByCode('urologie');
        self::assertInstanceOf(PlausibilityProblemArea::class, $urology);
        $this->curation->updateArea($urology, 'Urologie', 'Urology', [711, 712], ['urologisches_problem' => 1]);
        $this->lookup->reset();

        self::assertSame(3, $this->lookup->bonus(711, 'urologisches_problem')->points, 'the active snapshot is unchanged');
        self::assertSame(3, $this->lookup->bonus(713, 'urologisches_problem')->points);
        self::assertSame(3, $this->lookup->bonus(711, 'hodenschmerz')->points);

        $draft = $this->seedDraft();
        self::assertNotSame($seed->getId(), $draft->getId(), 'a new seed draft');
        $table = $this->lookup->table((int) $draft->getId());
        self::assertSame(1, $table->lookup(711, 'urologisches_problem')->points);
        self::assertSame(0, $table->lookup(713, 'urologisches_problem')->points, '713 left the area');
        self::assertSame(0, $table->lookup(711, 'hodenschmerz')->points, 'rule removed');
        // Seven codes (263, 713–718) and three rules removed, one rule changed; the area row itself is unchanged.
        self::assertSame(11, $this->auditEntries('encounter.plausibility.area_updated', [PlausibilityAreaCode::class, PlausibilityAreaDiagram::class]));

        $this->curation->deleteArea($urology);
        self::assertSame(0, $this->lookup->table((int) $draft->getId())->lookup(711, 'urologisches_problem')->points);
        self::assertSame(3, $this->lookup->bonus(711, 'urologisches_problem')->points, 'still the active snapshot');
        self::assertSame(1, $this->auditEntries('encounter.plausibility.area_deleted'));
    }

    public function testLookupResolvesTheStoredDiagramCodeToItsCanonicalCode(): void
    {
        $catalog = self::getContainer()->get(MtsCatalogCuration::class);
        $variant = new MtsDiagram('', 'Brustschmerz', MtsCatalogSource::FirstSeen, MtsCatalogStatus::Provisional);
        $catalog->createEntry($variant);
        self::getContainer()->get(PlausibilitySnapshots::class)->activate($this->seedDraft());

        self::assertSame(0, $this->lookup->bonus(331, 'brustschmerz')->points, 'a provisional diagram without rules');

        $thorax = $catalog->mergeTargets($variant);
        $catalog->merge($variant, array_values(array_filter($thorax, static fn ($entry): bool => 'thoraxschmerz' === $entry->getCode()))[0]);
        self::assertSame(3, $this->lookup->bonus(331, 'brustschmerz')->points, 'merged: read as thoraxschmerz');
        self::assertSame(3, $this->lookup->bonus(331, 'thoraxschmerz')->points);
        self::assertSame(0, $this->lookup->bonus(331, 'unbekannt')->points, 'an unknown code is used as it is');
    }

    private function seedDraft(): PlausibilitySnapshot
    {
        $draft = $this->repository->seedDraft();
        self::assertInstanceOf(PlausibilitySnapshot::class, $draft);
        self::assertSame(PlausibilitySnapshotStatus::Draft, $draft->getStatus());

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

    /**
     * Audit rows under the intent of the given entity classes (the area, its codes and rules are audited row by row).
     *
     * @param list<class-string> $classes
     */
    private function auditEntries(string $intent, array $classes = [PlausibilityProblemArea::class]): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE intent = :intent AND entity_class IN (:classes)',
            ['intent' => $intent, 'classes' => $classes],
            ['classes' => ArrayParameterType::STRING],
        );
    }
}
