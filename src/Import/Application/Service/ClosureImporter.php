<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Application\Contracts\RowReaderInterface;
use App\Import\Application\DTO\ImportSummary;
use App\Import\Application\Exception\ImportRunSupersededException;
use App\Import\Application\Exception\RowRejectException;
use App\Import\Application\Mapping\ClosureHospitalProfile;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Adapter\DoctrineClosureIntervalPersister;
use Psr\Log\LoggerInterface;

final readonly class ClosureImporter
{
    public function __construct(
        private RowReaderInterface $reader,
        private ClosureRowProcessor $processor,
        private DoctrineClosureIntervalPersister $persister,
        private RejectWriterInterface $rejectWriter,
        private LoggerInterface $logger,
    ) {
    }

    public function import(Import $import, ClosureHospitalProfile $profile): ImportSummary
    {
        if (null !== $profile->differingFileLabel) {
            $this->logger->info('closure.import.hospital_label', [
                'file_label' => $profile->differingFileLabel,
                'selected_hospital' => $profile->selectedDisplayName,
            ]);
        }

        if (null !== $profile->overriddenFileLabel) {
            $this->logger->info('closure.import.hospital_override', [
                'file_label' => $profile->overriddenFileLabel,
                'selected_hospital' => $profile->selectedDisplayName,
            ]);
        }

        $this->processor->warm();
        $this->persister->clear();

        $total = $ok = $rejected = 0;

        try {
            $lineNo = 1;

            // A row is one department and care level. A grouped record counts as several
            // rows; its rejects share the record's line number.
            foreach ($this->reader->rowsAssoc() as $record) {
                ++$lineNo;

                try {
                    $rows = $this->processor->split($record);
                } catch (RowRejectException $e) {
                    ++$total;
                    ++$rejected;
                    $this->reject($record, $e, $lineNo);

                    continue;
                }

                foreach ($rows as $row) {
                    ++$total;

                    try {
                        $this->processor->process($row, $import, $profile);
                        ++$ok;
                    } catch (RowRejectException $e) {
                        ++$rejected;
                        $this->reject($row, $e, $lineNo);
                    }
                }
            }

            $this->persister->flush();
            $this->rejectWriter->close();
            $this->logger->info('closure.import.summary', ['total' => $total, 'ok' => $ok, 'rejected' => $rejected]);

            return new ImportSummary($total, $ok, $rejected);
        } catch (ImportRunSupersededException $e) {
            // A newer run owns the import now: drop the pending chunk and commit nothing more.
            $this->persister->clear();
            $this->closeRejectWriterAfterError();

            throw $e;
        } catch (\Throwable $e) {
            $this->logger->critical('closure.import.abort.unexpected', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            try {
                $this->persister->flush();
            } catch (\Throwable $flushError) {
                $this->logger->critical('closure.import.abort.flush_failed', [
                    'exception' => $flushError::class,
                    'message' => $flushError->getMessage(),
                ]);
            }

            $this->closeRejectWriterAfterError();

            throw $e;
        }
    }

    /**
     * @param array<string, string> $row
     */
    private function reject(array $row, RowRejectException $e, int $lineNo): void
    {
        $messages = $e->messages();
        $this->rejectWriter->write($row, $messages, $lineNo);

        $this->logger->warning('closure.reject.row_rejected', array_merge([
            'line' => $lineNo,
            'messages' => $messages,
        ], $e->context()));
    }

    /**
     * Closing can fail as well (e.g. the entity manager was closed by a database error).
     * That must not hide the original error, which decides whether the run is retried.
     */
    private function closeRejectWriterAfterError(): void
    {
        try {
            $this->rejectWriter->close();
        } catch (ImportRunSupersededException) {
            // Expected after the run was superseded; the pending rejects are discarded.
        } catch (\Throwable $closeError) {
            $this->logger->critical('closure.import.abort.reject_close_failed', [
                'exception' => $closeError::class,
                'message' => $closeError->getMessage(),
            ]);
        }
    }
}
