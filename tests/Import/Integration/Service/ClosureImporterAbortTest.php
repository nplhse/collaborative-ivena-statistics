<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Application\Contracts\RowReaderInterface;
use App\Import\Application\DTO\ImportRunContext;
use App\Import\Application\Exception\ImportRunSupersededException;
use App\Import\Application\Mapping\ClosureHospitalGuard;
use App\Import\Application\Mapping\ClosureHospitalProfile;
use App\Import\Application\Service\ClosureImporter;
use App\Import\Application\Service\ClosureRowProcessor;
use App\Import\Application\Service\ImportRunCommitGuard;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Adapter\DoctrineClosureIntervalPersister;
use App\Import\Infrastructure\Monitoring\ImportRunTelemetry;
use App\Tests\Import\Doubles\Service\Adapter\InMemoryRowReader;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

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

        $importer->import(new Import(), $this->profile());
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

            $importer->import(new Import(), $this->profile());
        } finally {
            $em->getEventManager()->removeEventListener(Events::onFlush, $listener);
        }
    }

    public function testSupersededRunDropsThePendingChunkAndRethrows(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $guard = new ImportRunCommitGuard();
        // No import with this id exists, so the guarded commit finds a different current run.
        $guard->activate(new ImportRunContext(2_147_483_647, Uuid::v7()));
        $rejectWriter = $this->createMock(RejectWriterInterface::class);
        $rejectWriter->expects($this->once())->method('close')
            ->willThrowException(new ImportRunSupersededException(2_147_483_647, 'run'));

        $importer = new ClosureImporter(
            InMemoryRowReader::fromAssocRows([]),
            self::getContainer()->get(ClosureRowProcessor::class),
            new DoctrineClosureIntervalPersister($em, $guard, self::getContainer()->get(ImportRunTelemetry::class)),
            $rejectWriter,
            self::getContainer()->get(LoggerInterface::class),
        );

        $this->expectException(ImportRunSupersededException::class);

        $importer->import(new Import(), $this->profile());
    }

    public function testFailingRejectWriterCloseDoesNotHideTheOriginalError(): void
    {
        $rejectWriter = $this->createStub(RejectWriterInterface::class);
        $rejectWriter->method('close')->willThrowException(new \LogicException('entity manager closed'));

        $importer = new ClosureImporter(
            $this->throwingReader(new \RuntimeException('reader failed')),
            self::getContainer()->get(ClosureRowProcessor::class),
            self::getContainer()->get(DoctrineClosureIntervalPersister::class),
            $rejectWriter,
            self::getContainer()->get(LoggerInterface::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('reader failed');

        $importer->import(new Import(), $this->profile());
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

    private function profile(): ClosureHospitalProfile
    {
        return new ClosureHospitalGuard()->profile(1, [1 => 'Klinikum Beispiel'], []);
    }
}
