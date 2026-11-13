<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Import;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Import\EdVisitRow;
use App\Encounter\Application\Import\EdVisitRowMapper;
use App\Encounter\Application\Import\EncounterFileReader;
use App\Encounter\Application\Import\EncounterFileRejectedException;
use App\Encounter\Application\Import\EncounterFileValidator;
use App\Encounter\Application\Import\EncounterImportResult;
use App\Encounter\Application\Import\EncounterKeyGenerationChangedException;
use App\Encounter\Application\Import\EncounterManifest;
use App\Encounter\Application\Import\RowError;
use App\Encounter\Domain\Entity\EncounterImport;
use App\Encounter\Domain\Enum\EncounterFileRejection;
use App\Encounter\Infrastructure\Repository\EncounterKeyStateRepository;
use App\Encounter\Infrastructure\Repository\EncounterRepository;
use App\Import\Application\Service\ImportRunCommitGuard;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportReject;
use App\Import\Domain\ValueObject\ImportDataCoverage;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Processes one canonical exchange file of an encounter import (#1048, ADR 026, ADR 027, ADR 029).
 *
 * 1. Read and validate the whole file; a file-level finding throws EncounterFileRejectedException and
 *    nothing is written.
 * 2. Map every row; invalid rows become rejects, rows without a time anchor are skipped.
 * 3. Write everything in one guarded commit: lock the hospital's key state, check the key generation
 *    stored on the import (mismatch throws EncounterKeyGenerationChangedException), check or register
 *    the key check value, insert new visits row by row (known visits count as duplicates), record
 *    suspected duplicates and row rejects. A failure rolls the whole commit back.
 *
 * Row rejects record line number, field, error code and profile version only.
 */
final readonly class EncounterFileImporter
{
    /** Time anchors closer than this make a new visit with the same case key a suspected duplicate (ADR 029). */
    public const int SUSPECTED_DUPLICATE_WINDOW_MINUTES = 15;

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EncounterFileReader $reader,
        private EncounterFileValidator $validator,
        private EdVisitRowMapper $rowMapper,
        private EncounterRepository $encounters,
        private EncounterKeyStateRepository $keyStates,
        private ImportRunCommitGuard $commitGuard,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws EncounterFileRejectedException
     * @throws EncounterKeyGenerationChangedException
     */
    public function import(Import $import, EncounterImport $encounterImport, string $absolutePath): EncounterImportResult
    {
        $content = @file_get_contents($absolutePath);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Source file of encounter import #%d could not be read.', (int) $import->getId()));
        }

        $file = $this->reader->read($content);
        unset($content);
        [$manifest, $fields] = $this->validator->validate($file, $encounterImport->getSchema());
        $encounterImport->recordManifest($manifest->schemaVersion, $manifest->profile, $manifest->profileVersion, $manifest->toolVersion);

        /** @var list<EdVisitRow> $valid */
        $valid = [];
        /** @var array<int, non-empty-list<RowError>> $rejects */
        $rejects = [];
        $skipped = 0;
        foreach ($file->rows as $line => $cells) {
            $result = $this->rowMapper->map($line, $fields, $cells);
            if ($result->row instanceof EdVisitRow) {
                $valid[] = $result->row;
            } elseif ($result->isSkipped()) {
                ++$skipped;
            } else {
                /** @var non-empty-list<RowError> $errors */
                $errors = $result->errors;
                $rejects[$line] = $errors;
            }
        }

        $counts = $this->commitGuard->transactional(
            $this->entityManager,
            fn (): array => $this->write($import, $encounterImport, $manifest, $valid, $rejects),
        );

        return new EncounterImportResult(
            total: \count($file->rows),
            inserted: $counts['inserted'],
            duplicates: \count($valid) - $counts['inserted'],
            skipped: $skipped,
            rejected: \count($rejects),
            suspectedDuplicates: $counts['suspected'],
            coverage: $this->coverage($valid),
            insertedDays: $counts['days'],
        );
    }

    /**
     * @param list<EdVisitRow>                     $valid
     * @param array<int, non-empty-list<RowError>> $rejects
     *
     * @return array{inserted: int, suspected: int, days: list<string>}
     */
    private function write(Import $import, EncounterImport $encounterImport, EncounterManifest $manifest, array $valid, array $rejects): array
    {
        $importId = (int) $import->getId();
        $hospital = $import->getHospital() ?? throw new \LogicException(sprintf('Import #%d has no hospital.', $importId));
        $hospitalId = (int) $hospital->getId();
        $generation = $encounterImport->getKeyGeneration();

        $keyState = $this->keyStates->lockForImport($hospitalId, $this->clock->now());
        if ($keyState['generation'] !== $generation || $keyState['key_change_in_progress']) {
            throw new EncounterKeyGenerationChangedException($importId, $generation, $keyState['generation'], $keyState['key_change_in_progress']);
        }

        if (\in_array($manifest->keyCheck, $keyState['retired_check_values'], true)) {
            throw new EncounterFileRejectedException(EncounterFileRejection::KeyCheckRetired);
        }

        if (null === $keyState['active_check_value']) {
            $this->keyStates->registerCheckValue($hospitalId, $manifest->keyCheck);
        } elseif ($keyState['active_check_value'] !== $manifest->keyCheck) {
            throw new EncounterFileRejectedException(EncounterFileRejection::KeyCheckMismatch);
        }

        /** @var Hospital $hospitalReference */
        $hospitalReference = $this->entityManager->getReference(Hospital::class, $hospitalId);
        /** @var Import $importReference */
        $importReference = $this->entityManager->getReference(Import::class, $importId);

        $inserted = 0;
        $suspected = 0;
        $days = [];
        foreach ($valid as $row) {
            $encounter = $row->toEncounter($hospitalReference, $importReference, $generation);
            $encounterId = $this->encounters->insertIfNewReturningId($encounter);
            if (null === $encounterId) {
                continue;
            }

            ++$inserted;
            $days[$encounter->getAnchorAt()->format('Y-m-d')] = true;
            if ($this->recordSuspectedDuplicates($importId, $encounterId, $row->line, $encounter->getAnchorSource()->value)) {
                ++$suspected;
            }
        }

        foreach ($rejects as $line => $errors) {
            $reject = new ImportReject();
            $reject->setImport($importReference);
            $reject->setLineNumber($line);
            $reject->setMessages(array_map(static fn (RowError $error): string => $error->describe(), $errors));
            // Never the row or a cell value (ADR 026): only what identifies the producing profile.
            $reject->setRow(['profile' => $manifest->profile, 'profile_version' => $manifest->profileVersion]);
            $this->entityManager->persist($reject);
        }

        $this->entityManager->flush();

        $days = array_keys($days);
        sort($days);

        return ['inserted' => $inserted, 'suspected' => $suspected, 'days' => $days];
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

    /**
     * Visits of the file (new and known) per anchor day.
     *
     * @param list<EdVisitRow> $rows
     */
    private function coverage(array $rows): ImportDataCoverage
    {
        if ([] === $rows) {
            return ImportDataCoverage::empty();
        }

        $first = null;
        $last = null;
        $daily = [];
        foreach ($rows as $row) {
            $anchor = $row->times->anchorAt();
            if (!$anchor instanceof \DateTimeImmutable) {
                continue;
            }

            $first = !$first instanceof \DateTimeImmutable || $anchor < $first ? $anchor : $first;
            $last = !$last instanceof \DateTimeImmutable || $anchor > $last ? $anchor : $last;
            $day = $anchor->format('Y-m-d');
            $daily[$day] = ($daily[$day] ?? 0) + 1;
        }

        ksort($daily);

        return new ImportDataCoverage(\count($rows), $first, $last, $daily);
    }
}
