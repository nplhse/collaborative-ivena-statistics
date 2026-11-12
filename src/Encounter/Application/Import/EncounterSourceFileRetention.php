<?php

declare(strict_types=1);

namespace App\Encounter\Application\Import;

use App\Encounter\Application\Contract\EncounterImportReaderInterface;
use App\Import\Application\Service\ImportFileStorage;
use App\Shared\Infrastructure\Audit\AuditContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Deletes encounter upload files after the retention period (ADR 025, #1049).
 *
 * - Applies to every encounter import whose file is still kept: accepted files and files rejected for a
 *   possible processing error. Files that may hold direct identifiers were already deleted by the import.
 * - The age counts from the upload (`import.created_at`); reprocessing does not extend it. A file is
 *   deleted once it is older than `app.encounter.source_file_retention_days` days.
 * - Imports with a pending or running run are left for the next pass.
 * - The deletion date is stored on the encounter import; a file that is already gone is marked as deleted
 *   with the time of the pass. Imported encounters, rejects and the import record stay.
 * - Allocation and closure imports are never selected.
 */
final readonly class EncounterSourceFileRetention
{
    public const string LOG_EVENT = 'encounter.import.source_file.retention_deleted';

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EncounterImportReaderInterface $encounterImports,
        private ImportFileStorage $fileStorage,
        private EntityManagerInterface $entityManager,
        private AuditContext $auditContext,
        private ClockInterface $clock,
        #[Target('import')]
        private LoggerInterface $importLogger,
        #[Autowire(param: 'app.encounter.source_file_retention_days')]
        private int $retentionDays,
    ) {
    }

    /**
     * The upload time before which a kept file is deleted.
     */
    public function cutoff(): \DateTimeImmutable
    {
        return $this->clock->now()->modify(\sprintf('-%d days', $this->retentionDays));
    }

    public function retentionDays(): int
    {
        return $this->retentionDays;
    }

    public function run(bool $dryRun = false): EncounterSourceFileRetentionResult
    {
        $cutoff = $this->cutoff();
        $now = $this->clock->now();
        $deleted = [];
        $missing = [];
        $failed = [];

        foreach ($this->encounterImports->findWithKeptSourceFileUploadedBefore($cutoff) as $encounterImport) {
            $import = $encounterImport->getImport();
            $importId = (int) $import->getId();
            $path = $import->getFilePath();
            $exists = $this->fileStorage->exists($path);

            if ($dryRun) {
                if ($exists) {
                    $deleted[] = $importId;
                } else {
                    $missing[] = $importId;
                }

                continue;
            }

            if ($exists) {
                $this->fileStorage->delete($path, self::LOG_EVENT, $importId);
                if ($this->fileStorage->exists($path)) {
                    $failed[] = $importId;

                    continue;
                }

                $deleted[] = $importId;
            } else {
                $missing[] = $importId;
            }

            $encounterImport->markSourceFileDeleted($now);
        }

        if (!$dryRun && ([] !== $deleted || [] !== $missing)) {
            $this->auditContext->beginIntent(self::LOG_EVENT, ['retention_days' => $this->retentionDays]);
            try {
                $this->entityManager->flush();
            } finally {
                $this->auditContext->endIntent();
            }
        }

        $result = new EncounterSourceFileRetentionResult($cutoff, $deleted, $missing, $failed);
        if (!$dryRun) {
            $this->importLogger->info('encounter.import.source_file.retention_run', $result->metrics() + ['retention_days' => $this->retentionDays]);
        }

        return $result;
    }
}
