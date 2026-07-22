<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Service;

use App\Import\Application\Contracts\AllocationPersisterInterface;
use App\Import\Application\Contracts\AllocationRowProcessorInterface;
use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Application\Contracts\RowTypeDetectorInterface;
use App\Import\Application\Exception\RowRejectException;
use App\Import\Application\Service\AllocationImporter;
use App\Import\Application\Service\AllocationRowProcessorRegistry;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\AllocationRowType;
use App\Tests\Import\Doubles\Service\Adapter\InMemoryRejectWriter;
use App\Tests\Import\Doubles\Service\Adapter\InMemoryRowReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AllocationImporterTest extends TestCase
{
    private function createNoopProcessor(AllocationRowType $type): AllocationRowProcessorInterface
    {
        return new readonly class($type) implements AllocationRowProcessorInterface {
            public function __construct(private AllocationRowType $type)
            {
            }

            public function type(): AllocationRowType
            {
                return $this->type;
            }

            public function warm(): void
            {
            }

            public function process(array $row, Import $import, int $lineNo): void
            {
            }
        };
    }

    private function createThrowingProcessor(AllocationRowType $type, \Throwable $exception): AllocationRowProcessorInterface
    {
        return new readonly class($type, $exception) implements AllocationRowProcessorInterface {
            public function __construct(
                private AllocationRowType $type,
                private \Throwable $exception,
            ) {
            }

            public function type(): AllocationRowType
            {
                return $this->type;
            }

            public function warm(): void
            {
            }

            public function process(array $row, Import $import, int $lineNo): void
            {
                throw $this->exception;
            }
        };
    }

    public function testUnknownRowTypeIsRejectedAndSummarized(): void
    {
        $reader = InMemoryRowReader::fromAssocRows([
            ['foo' => 'bar'],
        ]);
        $rejectWriter = new InMemoryRejectWriter();

        $detector = $this->createStub(RowTypeDetectorInterface::class);
        $detector->method('detect')->willReturn(null);

        $persister = $this->createStub(AllocationPersisterInterface::class);

        $registry = new AllocationRowProcessorRegistry([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('reject.row_type_unknown', $this->callback(
                static fn (array $context): bool => ['Unable to detect a supported row type.'] === $context['messages'],
            ));

        $importer = new AllocationImporter($reader, $detector, $registry, $persister, $rejectWriter, $logger);

        $summary = $importer->import(new Import());

        self::assertSame(1, $summary->total);
        self::assertSame(0, $summary->ok);
        self::assertSame(1, $summary->rejected);

        $records = $rejectWriter->all();
        self::assertCount(1, $records);
        self::assertSame(['Unable to detect a supported row type.'], $records[0]['messages']);
    }

    public function testRowRejectExceptionFromProcessorIsWrittenAndSummarized(): void
    {
        $reader = InMemoryRowReader::fromAssocRows([
            ['pzc' => 'A1', 'age' => 'not-a-number'],
        ]);
        $rejectWriter = new InMemoryRejectWriter();

        $detector = $this->createStub(RowTypeDetectorInterface::class);
        $detector->method('detect')->willReturn(AllocationRowType::ALLOCATION);

        $persister = $this->createStub(AllocationPersisterInterface::class);

        $processor = $this->createThrowingProcessor(
            AllocationRowType::ALLOCATION,
            new RowRejectException(['age: invalid']),
        );
        $registry = new AllocationRowProcessorRegistry([$processor]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('reject.row_rejected', $this->callback(
                static fn (array $context): bool => ['age: invalid'] === $context['messages'],
            ));

        $importer = new AllocationImporter($reader, $detector, $registry, $persister, $rejectWriter, $logger);

        $summary = $importer->import(new Import());

        self::assertSame(1, $summary->total);
        self::assertSame(0, $summary->ok);
        self::assertSame(1, $summary->rejected);

        $records = $rejectWriter->all();
        self::assertCount(1, $records);
        self::assertSame(['age: invalid'], $records[0]['messages']);
    }

    public function testOkAndRejectedRowsAreCountedAndPersisterFlushesOnce(): void
    {
        $reader = InMemoryRowReader::fromAssocRows([
            ['pzc' => 'A1'],
            ['foo' => 'bar'],
        ]);
        $rejectWriter = new InMemoryRejectWriter();

        $detector = $this->createStub(RowTypeDetectorInterface::class);
        $detector->method('detect')->willReturnOnConsecutiveCalls(AllocationRowType::ALLOCATION, null);

        $persister = $this->createMock(AllocationPersisterInterface::class);
        $persister->expects($this->once())->method('flush');

        $processor = $this->createNoopProcessor(AllocationRowType::ALLOCATION);
        $registry = new AllocationRowProcessorRegistry([$processor]);

        $logger = $this->createStub(LoggerInterface::class);

        $importer = new AllocationImporter($reader, $detector, $registry, $persister, $rejectWriter, $logger);

        $summary = $importer->import(new Import());

        self::assertSame(2, $summary->total);
        self::assertSame(1, $summary->ok);
        self::assertSame(1, $summary->rejected);
    }

    public function testUnexpectedExceptionFromProcessorIsRethrownAndRejectWriterIsClosed(): void
    {
        $reader = InMemoryRowReader::fromAssocRows([
            ['pzc' => 'A1'],
        ]);

        $closeTrackingRejectWriter = new class(new InMemoryRejectWriter()) implements RejectWriterInterface {
            public bool $closed = false;

            public function __construct(private readonly InMemoryRejectWriter $inner)
            {
            }

            public function start(Import $import): void
            {
                $this->inner->start($import);
            }

            public function write(array $row, array $messages, ?int $line = null): void
            {
                $this->inner->write($row, $messages, $line);
            }

            public function close(): void
            {
                $this->closed = true;
                $this->inner->close();
            }

            public function getCount(): int
            {
                return $this->inner->getCount();
            }

            public function getPath(): ?string
            {
                return $this->inner->getPath();
            }

            public function getType(): string
            {
                return $this->inner->getType();
            }
        };

        $detector = $this->createStub(RowTypeDetectorInterface::class);
        $detector->method('detect')->willReturn(AllocationRowType::ALLOCATION);

        $persister = $this->createStub(AllocationPersisterInterface::class);

        $processor = $this->createThrowingProcessor(
            AllocationRowType::ALLOCATION,
            new \RuntimeException('boom'),
        );
        $registry = new AllocationRowProcessorRegistry([$processor]);

        $logger = $this->createStub(LoggerInterface::class);

        $importer = new AllocationImporter($reader, $detector, $registry, $persister, $closeTrackingRejectWriter, $logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        try {
            $importer->import(new Import());
        } finally {
            self::assertTrue($closeTrackingRejectWriter->closed);
        }
    }
}
