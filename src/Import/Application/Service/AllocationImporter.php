<?php

namespace App\Import\Application\Service;

use App\Import\Application\Contracts\AllocationImporterInterface;
use App\Import\Application\Contracts\AllocationPersisterInterface;
use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Application\Contracts\RowReaderInterface;
use App\Import\Application\Contracts\RowToDtoMapperInterface;
use App\Import\Application\DTO\ImportSummary;
use App\Import\Application\Exception\ImportException;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Mapping\AllocationImportFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AllocationImporter implements AllocationImporterInterface
{
    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly RowReaderInterface $reader,
        private readonly RowToDtoMapperInterface $mapper,
        private readonly AllocationImportFactory $factory,
        private readonly AllocationPersisterInterface $persister,
        private readonly RejectWriterInterface $rejectWriter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function import(Import $import): ImportSummary
    {
        $this->factory->warm();

        $total = $ok = $rejected = 0;

        try {
            foreach ($this->reader->rowsAssoc() as $i => $row) {
                ++$total;
                $lineNo = $i;

                try {
                    $dto = $this->mapper->mapAssoc($row);

                    $violations = $this->validator->validate($dto);

                    if (\count($violations) > 0) {
                        $messages = [];

                        foreach ($violations as $v) {
                            $messages[] = sprintf('%s: %s', $v->getPropertyPath(), $v->getMessage());
                        }

                        $this->rejectWriter->write($row, $messages, $lineNo);
                        ++$rejected;

                        $this->logger->warning('reject.validation', [
                            'line' => $lineNo,
                            'messages' => $messages,
                        ]);

                        continue;
                    }

                    $entity = $this->factory->fromDto($dto, $import);
                    $this->persister->persist($entity);
                    ++$ok;
                } catch (ImportException $e) {
                    $this->rejectWriter->write($row, [$e->summarize()], $lineNo);
                    ++$rejected;

                    $this->logger->error('reject.import_exception', array_merge([
                        'line' => $lineNo,
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
