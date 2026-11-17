<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Import;

use App\Encounter\Application\Import\EncounterFileReader;
use App\Encounter\Application\Import\EncounterFileRejectedException;
use App\Encounter\Application\Import\EncounterFileValidator;
use App\Encounter\Application\Import\EncounterImportResult;
use App\Encounter\Application\Import\EncounterKeyGenerationChangedException;
use App\Encounter\Application\Import\EncounterManifest;
use App\Encounter\Application\Import\EncounterSchemaHandlerRegistry;
use App\Encounter\Contract\Assignment\EncounterHospitalLockInterface;
use App\Encounter\Contract\Import\EncounterRecordRow;
use App\Encounter\Contract\Import\EncounterSchemaHandlerInterface;
use App\Encounter\Contract\Import\ReprocessingSchemaHandlerInterface;
use App\Encounter\Contract\Import\RowError;
use App\Encounter\Contract\Import\SchemaWriteContext;
use App\Encounter\Contract\Import\SchemaWriteResult;
use App\Encounter\Domain\Entity\EncounterImport;
use App\Encounter\Domain\Enum\EncounterFileRejection;
use App\Encounter\Infrastructure\Repository\EncounterKeyStateRepository;
use App\Import\Application\Service\ImportRunCommitGuard;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportReject;
use App\Import\Domain\ValueObject\ImportDataCoverage;
use Cois\Plugin\EdVisit\Infrastructure\Import\EdVisitWriteResult;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Processes one canonical exchange file of an encounter import (#1048, ADR 026, ADR 027, ADR 029,
 * ADR 037). The steps below are shared by every schema; the schema's handler maps the rows and writes
 * its records.
 *
 * 1. Find the handler of the schema chosen at upload; without one the file is rejected as unsupported.
 * 2. Read and validate the whole file; a file-level finding throws EncounterFileRejectedException and
 *    nothing is written.
 * 3. Map every row through the handler; invalid rows become rejects, rows the schema skips are counted.
 * 4. Write everything in one guarded commit: lock the hospital's key state, check the key generation
 *    stored on the import (mismatch throws EncounterKeyGenerationChangedException), check or register
 *    the key check value, let the handler write its records, record row rejects. A failure rolls the
 *    whole commit back.
 *
 * Row rejects record line number, field, error code and profile version only.
 */
final readonly class EncounterFileImporter
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EncounterFileReader $reader,
        private EncounterFileValidator $validator,
        private EncounterSchemaHandlerRegistry $handlers,
        private EncounterKeyStateRepository $keyStates,
        private ImportRunCommitGuard $commitGuard,
        private ClockInterface $clock,
        private EncounterHospitalLockInterface $hospitalLock,
    ) {
    }

    /**
     * @throws EncounterFileRejectedException
     * @throws EncounterKeyGenerationChangedException
     */
    public function import(Import $import, EncounterImport $encounterImport, string $absolutePath, bool $reprocess = false): EncounterImportResult
    {
        $handler = $this->handlers->find($encounterImport->getSchema()) ?? throw new EncounterFileRejectedException(EncounterFileRejection::SchemaUnsupported);

        $content = @file_get_contents($absolutePath);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Source file of encounter import #%d could not be read.', (int) $import->getId()));
        }

        $file = $this->reader->read($content);
        unset($content);
        [$manifest, $fields] = $this->validator->validate($file, $encounterImport->getSchema());
        // Reset in memory only: the new counters are written with the new data, so a failed run keeps the report of
        // the last successful one (#1300).
        $encounterImport->resetForRun();
        $encounterImport->recordManifest($manifest->schemaVersion, $manifest->profile, $manifest->profileVersion, $manifest->toolVersion);

        /** @var list<EncounterRecordRow> $valid */
        $valid = [];
        /** @var array<int, non-empty-list<RowError>> $rejects */
        $rejects = [];
        $skipped = 0;
        foreach ($file->rows as $line => $cells) {
            $result = $handler->mapRow($line, $fields, $cells);
            if ($result->row instanceof EncounterRecordRow) {
                $valid[] = $result->row;
            } elseif ($result->isSkipped()) {
                ++$skipped;
            } else {
                /** @var non-empty-list<RowError> $errors */
                $errors = $result->errors;
                $rejects[$line] = $errors;
            }
        }

        // One transaction also without an active run (the guard then runs the work as it is): the hospital lock and
        // the replacement of a previous run need it.
        $written = $this->entityManager->getConnection()->transactional(fn (): SchemaWriteResult => $this->commitGuard->transactional(
            $this->entityManager,
            fn (): SchemaWriteResult => $this->write($import, $encounterImport, $handler, $manifest, $valid, $rejects, $reprocess),
        ));

        return new EncounterImportResult(
            total: \count($file->rows),
            inserted: $written->inserted,
            duplicates: \count($valid) - $written->inserted,
            skipped: $skipped,
            rejected: \count($rejects),
            // Suspected duplicates are specific to ed_visit and stay in the core until #1300.
            suspectedDuplicates: $written instanceof EdVisitWriteResult ? $written->suspectedDuplicates : 0,
            coverage: $this->coverage($valid),
            insertedDays: $written->insertedDays,
            assignments: $written->assignments,
        );
    }

    /**
     * Reprocessing in the write transaction (#1300, ADR 029): what belongs to the import as a whole goes first
     * (rejects, row counters, pending assignments and the decisions on them, #1307), then the schema replaces its
     * records, keeping unchanged ones where it can. Nothing is committed before the new data, so a failed run
     * leaves the last successful one as it was.
     *
     * @param list<EncounterRecordRow> $valid
     */
    private function reprocess(Import $import, EncounterSchemaHandlerInterface $handler, SchemaWriteContext $context, array $valid): SchemaWriteResult
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM import_reject WHERE import_id = ?', [$context->importId]);
        $connection->executeStatement('DELETE FROM encounter_assignment_decision WHERE import_id = ?', [$context->importId]);
        $connection->executeStatement('DELETE FROM encounter_pending_assignment WHERE import_id = ?', [$context->importId]);
        $import->resetForReimport();
        $import->markAsRunning();

        if ($handler instanceof ReprocessingSchemaHandlerInterface) {
            return $handler->reprocess($context, $valid);
        }

        $handler->removeForImport($context->importId);

        return $handler->write($context, $valid);
    }

    /**
     * @param list<EncounterRecordRow>             $valid
     * @param array<int, non-empty-list<RowError>> $rejects
     */
    private function write(Import $import, EncounterImport $encounterImport, EncounterSchemaHandlerInterface $handler, EncounterManifest $manifest, array $valid, array $rejects, bool $reprocess): SchemaWriteResult
    {
        $importId = (int) $import->getId();
        $hospital = $import->getHospital() ?? throw new \LogicException(sprintf('Import #%d has no hospital.', $importId));
        $hospitalId = (int) $hospital->getId();
        $generation = $encounterImport->getKeyGeneration();

        // Serialises with the decisions, retries, the matcher and the cleanup of encounters (#1300).
        $this->hospitalLock->lock($hospitalId);

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

        $context = new SchemaWriteContext($hospitalId, $importId, $generation);
        if ($reprocess) {
            $written = $this->reprocess($import, $handler, $context, $valid);
        } else {
            $written = $handler->write($context, $valid);
        }

        /** @var Import $importReference */
        $importReference = $this->entityManager->getReference(Import::class, $importId);
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

        return $written;
    }

    /**
     * Valid rows of the file (new and known) per day of their coverage time.
     *
     * @param list<EncounterRecordRow> $rows
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
            $anchor = $row->coverageAt();
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
