<?php

namespace App\Import\Application\Service;

use App\Import\Application\Contracts\AllocationImporterInterface;
use App\Import\Application\Contracts\AllocationPersisterInterface;
use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Application\Contracts\RowReaderInterface;
use App\Import\Application\Contracts\RowTypeDetectorInterface;
use App\Import\Application\DTO\ImportSummary;
use App\Import\Application\Exception\RowRejectException;
use App\Import\Domain\Entity\Import;
use Psr\Log\LoggerInterface;

final class AllocationImporter implements AllocationImporterInterface
{
    public function __construct(
        private readonly RowReaderInterface $reader,
        private readonly RowTypeDetectorInterface $rowTypeDetector,
        private readonly AllocationRowProcessorRegistry $processorRegistry,
        private readonly AllocationPersisterInterface $persister,
        private readonly RejectWriterInterface $rejectWriter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function import(Import $import): ImportSummary
    {
        $this->processorRegistry->warmAll();

        $total = $ok = $rejected = 0;

        try {
            $lineNo = 1;

            foreach ($this->reader->rowsAssoc() as $row) {
                ++$total;
                ++$lineNo;

                try {
                    $type = $this->rowTypeDetector->detect($row);
                    if (null === $type) {
                        $messages = ['Unable to detect a supported row type.'];
                        $this->rejectWriter->write($row, $messages, $lineNo);
                        ++$rejected;

                        $this->logger->warning('reject.row_type_unknown', [
                            'line' => $lineNo,
                            'messages' => $messages,
                        ]);

                        continue;
                    }

                    $processor = $this->processorRegistry->get($type);
                    $processor->process($row, $import, $lineNo);
                    ++$ok;
                } catch (RowRejectException $e) {
                    $messages = $e->messages();
                    $this->rejectWriter->write($row, $messages, $lineNo);
                    ++$rejected;

                    $this->logger->warning('reject.row_rejected', array_merge([
                        'line' => $lineNo,
                        'messages' => $messages,
                    ], $e->context()));

                    continue;
                }
            }

            $this->persister->flush();

            $this->logger->info('import.summary', ['total' => $total, 'ok' => $ok, 'rejected' => $rejected]);

            return new ImportSummary($total, $ok, $rejected);
        } catch (\Throwable $e) {
            $this->logger->critical('import.abort.unexpected', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            try {
                $this->persister->flush();
            } catch (\Throwable $flushError) {
                $this->logger->critical('import.abort.flush_failed', [
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
