<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Application\Catalog;

use App\Encounter\Application\Catalog\MtsCatalog;
use App\Encounter\Application\Catalog\MtsCatalogCuration;
use App\Encounter\Application\Catalog\MtsCatalogException;
use App\Encounter\Domain\Entity\MtsAlias;
use App\Encounter\Domain\Entity\MtsCatalogItem;
use App\Encounter\Domain\Entity\MtsDiagram;
use App\Encounter\Domain\Entity\MtsDiagramIndicator;
use App\Encounter\Domain\Entity\MtsIndicator;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use App\Encounter\Domain\Enum\MtsCatalogSource;
use App\Encounter\Domain\Enum\MtsCatalogStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Maintenance of the MTS catalog (ADR 032) against the seeded catalog: derived codes, duplicates and collisions,
 * aliases, assignments, merge and undo, and the audit intents.
 */
#[ResetDatabase]
final class MtsCatalogCurationTest extends KernelTestCase
{
    private MtsCatalogCuration $curation;

    private MtsCatalog $catalog;

    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->curation = self::getContainer()->get(MtsCatalogCuration::class);
        $this->catalog = self::getContainer()->get(MtsCatalog::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testNewEntryGetsTheCodeOfItsGermanNameOnce(): void
    {
        $diagram = $this->diagram('Augenprobleme bei Kindern');

        self::assertSame('augenprobleme_bei_kindern', $diagram->getCode());
        self::assertSame('Augenprobleme bei Kindern', $this->catalog->diagram('augenprobleme_bei_kindern')?->germanName, 'readers see it at once');

        $diagram->setGermanName('Augenprobleme (Kinder)');
        $this->curation->updateEntry($diagram);
        self::assertSame('augenprobleme_bei_kindern', $diagram->getCode(), 'a corrected name keeps the code');
        self::assertSame('Augenprobleme (Kinder)', $this->catalog->diagram('augenprobleme_bei_kindern')?->germanName);
        self::assertSame(1, $this->auditEntries('encounter.mts_catalog.entry_created'));
        self::assertSame(1, $this->auditEntries('encounter.mts_catalog.entry_updated'));
    }

    public function testSameLabelIsADuplicateAndAnotherLabelWithTheSameCodeACollision(): void
    {
        self::assertSame(MtsCatalogException::DUPLICATE, $this->refusal(fn (): MtsDiagram => $this->diagram('Thoraxschmerz')));
        self::assertSame(MtsCatalogException::DUPLICATE, $this->refusal(fn (): MtsDiagram => $this->diagram('thoraxschmerz ')), 'case and spaces do not count');

        self::assertSame(MtsCatalogException::COLLISION, $this->refusal(fn (): MtsDiagram => $this->diagram('Thoraxschmerz!')), 'another label with the same code');
        self::assertNull($this->curation->conflictFor(MtsCatalogKind::Diagram, 'Thorax-Schmerz'), 'thorax_schmerz is another code');
        self::assertCount(40, $this->catalog->diagrams(), 'nothing was created');
    }

    public function testCollisionIsReportedWithTheExistingLabel(): void
    {
        // "Stürze" and "Stuerze" both give the code stuerze.
        $collision = $this->curation->conflictFor(MtsCatalogKind::Diagram, 'Stuerze');

        self::assertInstanceOf(MtsCatalogException::class, $collision);
        self::assertSame(MtsCatalogException::COLLISION, $collision->reason);
        self::assertSame(['code' => 'stuerze', 'label' => 'Stuerze', 'existing' => 'Stürze', 'target' => 'stuerze'], $collision->parameters());
        self::assertNull($this->curation->conflictFor(MtsCatalogKind::Indicator, 'Stuerze'), 'codes are unique per kind');
        self::assertSame(MtsCatalogException::EMPTY_CODE, $this->curation->conflictFor(MtsCatalogKind::Diagram, ' – ')?->reason);
    }

    public function testAliasCodeIsUniqueAcrossEntriesAndAliasesAndResolvesToItsTarget(): void
    {
        $alias = $this->alias(MtsCatalogKind::Diagram, 'Brustschmerz', 'thoraxschmerz');

        self::assertSame('brustschmerz', $alias->getCode());
        self::assertSame('thoraxschmerz', $this->catalog->diagram('brustschmerz')?->code);
        self::assertSame(['thoraxschmerz', 'brustschmerz'], $this->catalog->codesResolvingTo(MtsCatalogKind::Diagram, 'thoraxschmerz'));

        self::assertSame(MtsCatalogException::DUPLICATE, $this->curation->conflictFor(MtsCatalogKind::Diagram, 'Brustschmerz')?->reason, 'an entry cannot take an alias code');
        self::assertSame(
            ['code' => 'brustschmerz', 'label' => 'Brustschmerz.', 'existing' => 'Brustschmerz', 'target' => 'thoraxschmerz'],
            $this->curation->conflictFor(MtsCatalogKind::Diagram, 'Brustschmerz.')?->parameters(),
            'a collision with an alias names the alias label and its target',
        );
        self::assertSame(MtsCatalogException::DUPLICATE, $this->refusal(fn (): MtsAlias => $this->alias(MtsCatalogKind::Diagram, 'Thoraxschmerz', 'kopfschmerz')), 'an alias cannot take an entry code');
        self::assertSame(MtsCatalogException::TARGET_NOT_ACTIVE, $this->refusal(fn (): MtsAlias => $this->alias(MtsCatalogKind::Diagram, 'Kopfweh', 'heiss')), 'target of another kind');
        self::assertSame(MtsCatalogException::TARGET_NOT_ACTIVE, $this->refusal(fn (): MtsAlias => $this->alias(MtsCatalogKind::Diagram, 'Kopfweh', 'brustschmerz')), 'an alias never points to an alias');

        $alias->setTargetCode('kopfschmerz');
        $this->curation->updateAlias($alias);
        self::assertSame('kopfschmerz', $this->catalog->diagram('brustschmerz')?->code);

        $this->curation->deleteAlias($alias);
        self::assertNull($this->catalog->diagram('brustschmerz'));
        self::assertSame(1, $this->auditEntries('encounter.mts_catalog.alias_created'));
        self::assertSame(1, $this->auditEntries('encounter.mts_catalog.alias_updated'));
        self::assertSame(1, $this->auditEntries('encounter.mts_catalog.alias_deleted'));
    }

    public function testMergeTurnsAProvisionalEntryIntoAnAliasAndUndoRestoresIt(): void
    {
        $provisional = $this->diagram('Brustenge', MtsCatalogStatus::Provisional);
        $target = $this->find(MtsDiagram::class, 'thoraxschmerz');

        $this->curation->merge($provisional, $target);

        $this->entityManager->clear();
        $merged = $this->find(MtsDiagram::class, 'brustenge');
        self::assertSame(MtsCatalogStatus::Merged, $merged->getStatus(), 'the entry row stays');
        $alias = $this->entityManager->getRepository(MtsAlias::class)->findOneBy(['kind' => MtsCatalogKind::Diagram, 'code' => 'brustenge']);
        self::assertInstanceOf(MtsAlias::class, $alias);
        self::assertSame(['Brustenge', 'thoraxschmerz'], [$alias->getLabel(), $alias->getTargetCode()]);
        self::assertTrue($this->curation->isMergeAlias($alias));
        self::assertSame('thoraxschmerz', $this->catalog->diagram('brustenge')?->code, 'stored encounters resolve to the target');
        self::assertArrayNotHasKey('brustenge', $this->catalog->diagrams(), 'a merged entry is not listed');
        self::assertSame(MtsCatalogException::MERGE_ALIAS, $this->refusal(fn () => $this->curation->deleteAlias($alias)));
        self::assertSame(MtsCatalogException::MERGE_ALIAS, $this->refusal(fn () => $this->curation->updateAlias($alias)));

        $this->curation->undoMerge($merged);

        $this->entityManager->clear();
        self::assertSame(MtsCatalogStatus::Provisional, $this->find(MtsDiagram::class, 'brustenge')->getStatus());
        self::assertNull($this->entityManager->getRepository(MtsAlias::class)->findOneBy(['kind' => MtsCatalogKind::Diagram, 'code' => 'brustenge']));
        self::assertSame('brustenge', $this->catalog->diagram('brustenge')?->code, 'the code resolves to the entry again');
        self::assertSame([[MtsAlias::class, 'create'], [MtsDiagram::class, 'update']], $this->auditActions('encounter.mts_catalog.merged'), 'one intent: alias created, status changed');
        self::assertSame([[MtsAlias::class, 'delete'], [MtsDiagram::class, 'update']], $this->auditActions('encounter.mts_catalog.merge_undone'));
        self::assertSame(MtsCatalogException::NOT_MERGED, $this->refusal(fn () => $this->curation->undoMerge($this->find(MtsDiagram::class, 'brustenge'))));
    }

    public function testMergeIsRefusedForCuratedEntriesDependenciesAndInvalidTargets(): void
    {
        $thorax = $this->find(MtsDiagram::class, 'thoraxschmerz');
        $headache = $this->find(MtsDiagram::class, 'kopfschmerz');
        self::assertSame(MtsCatalogException::MERGE_NOT_PROVISIONAL, $this->refusal(fn () => $this->curation->merge($thorax, $headache)));

        $provisional = $this->diagram('Brustenge', MtsCatalogStatus::Provisional);
        self::assertSame(MtsCatalogException::MERGE_INTO_ITSELF, $this->refusal(fn () => $this->curation->merge($provisional, $provisional)));
        self::assertSame(MtsCatalogException::TARGET_NOT_ACTIVE, $this->refusal(fn () => $this->curation->merge($provisional, $this->find(MtsIndicator::class, 'heiss'))));

        $alias = $this->alias(MtsCatalogKind::Diagram, 'Enge in der Brust', 'brustenge');
        self::assertSame(MtsCatalogException::MERGE_HAS_ALIASES, $this->refusal(fn () => $this->curation->merge($provisional, $thorax)));
        $this->curation->deleteAlias($alias);

        $assignment = new MtsDiagramIndicator($provisional, $this->find(MtsIndicator::class, 'heiss'));
        $this->curation->assign($assignment);
        self::assertSame(MtsCatalogException::MERGE_HAS_ASSIGNMENTS, $this->refusal(fn () => $this->curation->merge($provisional, $thorax)));
        $this->curation->unassign($assignment);

        $other = $this->diagram('Brustdruck', MtsCatalogStatus::Provisional);
        $this->curation->merge($other, $thorax);
        self::assertSame(MtsCatalogException::TARGET_NOT_ACTIVE, $this->refusal(fn () => $this->curation->merge($provisional, $other)), 'a merged entry is no target');

        $this->curation->merge($provisional, $thorax);
        self::assertSame(MtsCatalogStatus::Merged, $provisional->getStatus());
    }

    public function testAssignmentsAreUniqueAndNeedActiveEntries(): void
    {
        $thorax = $this->find(MtsDiagram::class, 'thoraxschmerz');
        $hot = $this->find(MtsIndicator::class, 'heiss');

        $this->curation->assign(new MtsDiagramIndicator($thorax, $hot));
        self::assertSame(MtsCatalogException::ASSIGNMENT_EXISTS, $this->refusal(fn () => $this->curation->assign(new MtsDiagramIndicator($thorax, $hot))));

        $merged = $this->diagram('Brustenge', MtsCatalogStatus::Provisional);
        $this->curation->merge($merged, $thorax);
        self::assertSame(MtsCatalogException::ASSIGNMENT_NOT_ACTIVE, $this->refusal(fn () => $this->curation->assign(new MtsDiagramIndicator($merged, $hot))));
        self::assertSame(1, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM encounter_mts_diagram_indicator'));
        self::assertSame(1, $this->auditEntries('encounter.mts_catalog.assignment_created'));
    }

    private function diagram(string $germanName, MtsCatalogStatus $status = MtsCatalogStatus::Curated): MtsDiagram
    {
        $diagram = new MtsDiagram('', $germanName, MtsCatalogSource::Manual, $status);
        $this->curation->createEntry($diagram);

        return $diagram;
    }

    private function alias(MtsCatalogKind $kind, string $label, string $target): MtsAlias
    {
        $alias = new MtsAlias($kind, '', $label, $target);
        $this->curation->createAlias($alias);

        return $alias;
    }

    /**
     * @template T of MtsCatalogItem
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function find(string $class, string $code): MtsCatalogItem
    {
        $entry = $this->entityManager->getRepository($class)->findOneBy(['code' => $code]);
        self::assertInstanceOf($class, $entry);

        return $entry;
    }

    private function refusal(callable $change): string
    {
        try {
            $change();
        } catch (MtsCatalogException $exception) {
            return $exception->reason;
        }

        self::fail('The change was not refused.');
    }

    /**
     * @return list<array{string, string}> entity class and action, ordered by class
     */
    private function auditActions(string $intent): array
    {
        $rows = self::getContainer()->get(Connection::class)->fetchAllNumeric(
            'SELECT entity_class, action FROM audit_log WHERE intent = ? ORDER BY entity_class, action',
            [$intent],
        );

        return array_map(static fn (array $row): array => [(string) $row[0], (string) $row[1]], $rows);
    }

    private function auditEntries(string $intent): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM audit_log WHERE intent = ?', [$intent]);
    }
}
