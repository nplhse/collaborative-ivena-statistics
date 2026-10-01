<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Application\Contracts\RowReaderInterface;
use App\Import\Application\DTO\ImportSummary;
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

        $this->processor->warm();
        $this->persister->clear();

        $total = $ok = $rejected = 0;

        try {
            $lineNo = 1;

            foreach ($this->reader->rowsAssoc() as $row) {
                ++$total;
                ++$lineNo;

                try {
                    $this->processor->process($row, $import, $profile);
                    ++$ok;
                } catch (RowRejectException $e) {
                    $messages = $e->messages();
                    $this->rejectWriter->write($row, $messages, $lineNo);
                    ++$rejected;

                    $this->logger->warning('closure.reject.row_rejected', array_merge([
                        'line' => $lineNo,
                        'messages' => $messages,
                    ], $e->context()));
                }
            }

            $this->persister->flush();
            $this->logger->info('closure.import.summary', ['total' => $total, 'ok' => $ok, 'rejected' => $rejected]);

            return new ImportSummary($total, $ok, $rejected);
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

            throw $e;
        } finally {
            $this->rejectWriter->close();
        }
    }
}
