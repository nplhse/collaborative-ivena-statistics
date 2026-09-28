<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Application\Contracts\RowReaderInterface;
use App\Import\Application\Service\ClosureImporter;
use App\Import\Application\Service\ClosureRowProcessor;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Adapter\DoctrineClosureIntervalPersister;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

final class ClosureImporterAbortTest extends DatabaseKernelTestCase
{
    public function testUnexpectedReaderFailureIsLoggedAndRethrown(): void
    {
        $importer = $this->importer(
            reader: $this->throwingReader(new \RuntimeException('reader failed')),
            persister: self::getContainer()->get(DoctrineClosureIntervalPersister::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('reader failed');

        $importer->import(new Import());
    }

    public function testFlushFailureDuringAbortIsLoggedAndOriginalErrorIsRethrown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $listener = new class {
            public function onFlush(OnFlushEventArgs $args): never
            {
                throw new \RuntimeException('flush failed');
            }
        };
        $em->getEventManager()->addEventListener(Events::onFlush, $listener);

        $importer = $this->importer(
            reader: $this->throwingReader(new \RuntimeException('reader failed')),
            persister: self::getContainer()->get(DoctrineClosureIntervalPersister::class),
        );

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('reader failed');

            $importer->import(new Import());
        } finally {
            $em->getEventManager()->removeEventListener(Events::onFlush, $listener);
        }
    }

    private function importer(RowReaderInterface $reader, DoctrineClosureIntervalPersister $persister): ClosureImporter
    {
        return new ClosureImporter(
            $reader,
            self::getContainer()->get(ClosureRowProcessor::class),
            $persister,
            $this->createStub(RejectWriterInterface::class),
            self::getContainer()->get(LoggerInterface::class),
        );
    }

    private function throwingReader(\Throwable $error): RowReaderInterface
    {
        return new readonly class($error) implements RowReaderInterface {
            public function __construct(private \Throwable $error)
            {
            }

            public function rows(): iterable
            {
                return [];
            }

            public function header(): ?array
            {
                return null;
            }

            public function rowsAssoc(): iterable
            {
                throw $this->error;
            }
        };
    }
}
