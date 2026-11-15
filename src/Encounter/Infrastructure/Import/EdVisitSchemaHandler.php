<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Import;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Catalog\MtsCatalogFirstSeenWriter;
use App\Encounter\Application\Dimension\EdVisitDimension;
use App\Encounter\Application\Import\EdVisitRow;
use App\Encounter\Application\Import\EdVisitRowMapper;
use App\Encounter\Contract\Import\EncounterSchemaHandlerInterface;
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
final readonly class EdVisitSchemaHandler implements EncounterSchemaHandlerInterface
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
        /** @var Hospital $hospitalReference */
        $hospitalReference = $this->entityManager->getReference(Hospital::class, $context->hospitalId);
        /** @var Import $importReference */
        $importReference = $this->entityManager->getReference(Import::class, $context->importId);

        $inserted = 0;
        $suspected = 0;
        $days = [];
        $diagrams = [];
        $indicators = [];
        foreach ($rows as $row) {
            if (!$row instanceof EdVisitRow) {
                throw new \LogicException(sprintf('The ed_visit handler got a row of %s.', $row::class));
            }

            $encounter = $row->toEncounter($hospitalReference, $importReference, $context->keyGeneration);
            $encounterId = $this->encounters->insertIfNewReturningId($encounter);
            if (null === $encounterId) {
                continue;
            }

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
            $days[$encounter->getAnchorAt()->format('Y-m-d')] = true;
            if ($this->recordSuspectedDuplicates($context->importId, $encounterId, $row->line, $encounter->getAnchorSource()->value)) {
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

    #[\Override]
    public function removeForImport(int $importId): int
    {
        return $this->encounters->deleteByImportId($importId);
    }

    /**
     * @return bool whether the new visit is a suspected duplicate of at least one earlier visit
     */
    private function recordSuspectedDuplicates(int $importId, int $encounterId, int $line, string $anchorSource): bool
    {
        $matches = $this->encounters->findSuspectedDuplicatesOf($encounterId, self::SUSPECTED_DUPLICATE_WINDOW_MINUTES);
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
