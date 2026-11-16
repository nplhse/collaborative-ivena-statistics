<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Import;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Catalog\MtsCatalogFirstSeenWriter;
use App\Encounter\Application\Dimension\EdVisitDimension;
use App\Encounter\Application\Import\EdVisitRow;
use App\Encounter\Application\Import\EdVisitRowMapper;
use App\Encounter\Contract\Import\EncounterRecordRow;
use App\Encounter\Contract\Import\ReprocessingSchemaHandlerInterface;
use App\Encounter\Contract\Import\RowMappingResult;
use App\Encounter\Contract\Import\SchemaWriteContext;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use App\Encounter\Infrastructure\Repository\EncounterRepository;
use App\Import\Domain\Entity\Import;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Schema handler of `ed_visit` (ADR 026, ADR 029): every row is one ED visit and creates its encounter.
 *
 * Inserts new visits row by row (known visits count as duplicates), records suspected duplicates, and
 * turns MTS diagram and indicator codes of the new visits that resolve to nothing into provisional
 * catalog entries (ADR 032, #1157); the visits keep the codes as sent.
 *
 * Until MTS moves into its bundle (#1300) this handler is part of the core and may use Encounter
 * internals; plugins only see the contract (ADR 037).
 *
 * @psalm-suppress UnusedClass Tagged via EncounterSchemaHandlerInterface.
 */
final readonly class EdVisitSchemaHandler implements ReprocessingSchemaHandlerInterface
{
    /** Time anchors closer than this make a new visit with the same case key a suspected duplicate (ADR 029). */
    public const int SUSPECTED_DUPLICATE_WINDOW_MINUTES = 15;

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EdVisitRowMapper $rowMapper,
        private EncounterRepository $encounters,
        private MtsCatalogFirstSeenWriter $firstSeen,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function schema(): string
    {
        return EdVisitDimension::SCHEMA;
    }

    #[\Override]
    public function createsEncounters(): bool
    {
        return true;
    }

    #[\Override]
    public function mapRow(int $line, array $fields, array $cells): RowMappingResult
    {
        return $this->rowMapper->map($line, $fields, $cells);
    }

    #[\Override]
    public function write(SchemaWriteContext $context, array $rows): EdVisitWriteResult
    {
        return $this->writeRows($context, $rows, []);
    }

    /**
     * Reconciles the import's visits by fingerprint (#1300, ADR 029): a visit still in the file keeps its
     * encounter, uid and ED matching history and gets the file's values; visits no longer in the file are
     * removed; new rows are inserted. Suspected duplicates of the import are found again for every visit. The
     * days of all visits of the file are reported, so the matcher reruns for them.
     */
    #[\Override]
    public function reprocess(SchemaWriteContext $context, array $rows): EdVisitWriteResult
    {
        $connection = $this->entityManager->getConnection();
        /** @var array<string, int|string> $existing encounter id by visit fingerprint */
        $existing = $connection->fetchAllKeyValue('SELECT visit_fingerprint, encounter_id FROM ed_visit WHERE import_id = ?', [$context->importId]);
        $connection->executeStatement('DELETE FROM encounter_suspected_duplicate WHERE import_id = ?', [$context->importId]);

        $inFile = [];
        foreach ($rows as $row) {
            if ($row instanceof EdVisitRow) {
                $inFile[$row->visitFingerprint] = true;
            }
        }
        $this->deleteVisits(array_values(array_map(intval(...), array_diff_key($existing, $inFile))));

        return $this->writeRows($context, $rows, array_map(intval(...), array_intersect_key($existing, $inFile)));
    }

    /**
     * @param list<EncounterRecordRow> $rows
     * @param array<string, int>       $kept encounter id of the import's visits that stay, by fingerprint
     */
    private function writeRows(SchemaWriteContext $context, array $rows, array $kept): EdVisitWriteResult
    {
        /** @var Hospital $hospitalReference */
        $hospitalReference = $this->entityManager->getReference(Hospital::class, $context->hospitalId);
        /** @var Import $importReference */
        $importReference = $this->entityManager->getReference(Import::class, $context->importId);

        $inserted = 0;
        $suspected = 0;
        $days = [];
        $diagrams = [];
        $indicators = [];
        // Visits of this import not reached yet in the file: a visit is compared only with earlier ones, as when
        // the file was first imported.
        $notYetReached = array_flip($kept);
        foreach ($rows as $row) {
            if (!$row instanceof EdVisitRow) {
                throw new \LogicException(sprintf('The ed_visit handler got a row of %s.', $row::class));
            }

            $visit = $row->toEdVisit($hospitalReference, $importReference, $context->keyGeneration);
            if (isset($kept[$row->visitFingerprint])) {
                $encounterId = $kept[$row->visitFingerprint];
                $this->encounters->updateKnown($visit, $encounterId);
            } else {
                $encounterId = $this->encounters->insertIfNewReturningId($visit);
                if (null === $encounterId) {
                    continue;
                }
            }
            unset($notYetReached[$encounterId]);

            ++$inserted;
            foreach ([$row->triage->getDiagram(), $row->retriage->getDiagram()] as $code) {
                if (null !== $code) {
                    $diagrams[$code] = true;
                }
            }
            foreach ([$row->triage->getIndicator(), $row->retriage->getIndicator()] as $code) {
                if (null !== $code) {
                    $indicators[$code] = true;
                }
            }
            $days[$visit->getAnchorAt()->format('Y-m-d')] = true;
            if ($this->recordSuspectedDuplicates($context->importId, $encounterId, $row->line, $visit->getAnchorSource()->value, $notYetReached)) {
                ++$suspected;
            }
        }

        $seenAt = $this->clock->now();
        $this->firstSeen->record(MtsCatalogKind::Diagram, array_fill_keys(array_map(strval(...), array_keys($diagrams)), $seenAt), ['import_id' => $context->importId]);
        $this->firstSeen->record(MtsCatalogKind::Indicator, array_fill_keys(array_map(strval(...), array_keys($indicators)), $seenAt), ['import_id' => $context->importId]);

        $days = array_map(strval(...), array_keys($days));
        sort($days);

        return new EdVisitWriteResult($inserted, $days, $suspected);
    }

    /**
     * Removes the import's ED visits (import deletion; ADR 029) with what belongs to them, see deleteVisits().
     */
    #[\Override]
    public function removeForImport(int $importId): int
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM encounter_suspected_duplicate WHERE import_id = ?', [$importId]);
        $visits = array_map(intval(...), $connection->fetchFirstColumn('SELECT encounter_id FROM ed_visit WHERE import_id = ?', [$importId]));
        $this->deleteVisits($visits);

        return \count($visits);
    }

    /**
     * Removes ED visits with their ED matching history, the suspected duplicates and validation items that
     * name them (their encounter stays, so the foreign keys no longer cascade). The encounters keep their
     * effective IVENA assignment and the records of other imports (#1300).
     *
     * @param list<int> $encounterIds
     */
    private function deleteVisits(array $encounterIds): void
    {
        if ([] === $encounterIds) {
            return;
        }

        $connection = $this->entityManager->getConnection();
        $ids = ['{'.implode(',', $encounterIds).'}'];
        $connection->executeStatement('DELETE FROM encounter_match WHERE encounter_id = ANY(CAST(? AS INT[]))', $ids);
        $connection->executeStatement('DELETE FROM encounter_suspected_duplicate WHERE encounter_id = ANY(CAST(? AS INT[])) OR existing_encounter_id = ANY(CAST(? AS INT[]))', [...$ids, ...$ids]);
        $connection->executeStatement('DELETE FROM encounter_validation_item WHERE encounter_id = ANY(CAST(? AS INT[]))', $ids);
        $connection->executeStatement('UPDATE encounter_validation_item SET replacement_encounter_id = NULL WHERE replacement_encounter_id = ANY(CAST(? AS INT[]))', $ids);
        $connection->executeStatement('DELETE FROM ed_visit WHERE encounter_id = ANY(CAST(? AS INT[]))', $ids);
    }

    /**
     * An ED visit is an encounter of its own and never waits for one (no pending records).
     */
    #[\Override]
    public function attach(string $recordId, int $encounterId): bool
    {
        return false;
    }

    /**
     * @return bool whether the new visit is a suspected duplicate of at least one earlier visit
     */
    /**
     * @param array<int, string> $notYetReached fingerprint by encounter id of the visits of this import later in the file, which are not compared
     */
    private function recordSuspectedDuplicates(int $importId, int $encounterId, int $line, string $anchorSource, array $notYetReached): bool
    {
        $matches = array_values(array_filter(
            $this->encounters->findSuspectedDuplicatesOf($encounterId, self::SUSPECTED_DUPLICATE_WINDOW_MINUTES),
            static fn (array $match): bool => !isset($notYetReached[$match['id']]),
        ));
        foreach ($matches as $match) {
            $this->entityManager->getConnection()->executeStatement(
                'INSERT INTO encounter_suspected_duplicate (import_id, encounter_id, existing_encounter_id, line_number, anchor_difference_minutes, anchor_source, existing_anchor_source) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$importId, $encounterId, $match['id'], $line, $match['difference_minutes'], $anchorSource, $match['anchor_source']],
                [Types::INTEGER, Types::INTEGER, Types::INTEGER, Types::INTEGER, Types::SMALLINT, Types::STRING, Types::STRING],
            );
        }

        return [] !== $matches;
    }
}
