<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application;

use App\Import\Application\Service\ImportRequeueResumeResolver;
use App\Import\Domain\Entity\ImportBatchRun;
use App\Import\Domain\Entity\ImportBatchRunItem;
use App\Import\Domain\Enum\ImportBatchRunItemStatus;
use PHPUnit\Framework\TestCase;

final class ImportRequeueResumeResolverTest extends TestCase
{
    private ImportRequeueResumeResolver $resolver;

    /** @var list<array{id: int, name: ?string, filePath: ?string}> */
    private array $imports;

    protected function setUp(): void
    {
        $this->resolver = new ImportRequeueResumeResolver();
        $this->imports = [
            ['id' => 5, 'name' => 'A', 'filePath' => 'a.csv'],
            ['id' => 10, 'name' => 'B', 'filePath' => 'b.csv'],
            ['id' => 20, 'name' => 'C', 'filePath' => 'c.csv'],
        ];
    }

    public function testReturnsAllImportsWhenNoRun(): void
    {
        self::assertSame($this->imports, $this->resolver->resolveSlice($this->imports, null, 3));
    }

    public function testResumeAfterRunningRetriesSameImport(): void
    {
        $run = $this->runWithLastItem(10, ImportBatchRunItemStatus::Running, 1);

        $slice = $this->resolver->resolveSlice($this->imports, $run, 3);

        self::assertSame([
            ['id' => 10, 'name' => 'B', 'filePath' => 'b.csv'],
            ['id' => 20, 'name' => 'C', 'filePath' => 'c.csv'],
        ], $slice);
    }

    public function testResumeAfterQueuedStartsWithNextImport(): void
    {
        $run = $this->runWithLastItem(10, ImportBatchRunItemStatus::Queued, 1);

        $slice = $this->resolver->resolveSlice($this->imports, $run, 3);

        self::assertSame([
            ['id' => 20, 'name' => 'C', 'filePath' => 'c.csv'],
        ], $slice);
    }

    public function testResumeAfterDispatchFailedRetriesSameImport(): void
    {
        $run = $this->runWithLastItem(5, ImportBatchRunItemStatus::DispatchFailed, 2);

        $slice = $this->resolver->resolveSlice($this->imports, $run, 3);

        self::assertSame($this->imports, $slice);
    }

    public function testMaxRetriesReturnsEmptySlice(): void
    {
        $run = $this->runWithLastItem(5, ImportBatchRunItemStatus::DispatchFailed, 3);

        $slice = $this->resolver->resolveSlice($this->imports, $run, 3);

        self::assertSame([], $slice);
        self::assertNull($this->resolver->resolveStartImportId($run->getLastItem(), 3));
    }

    public function testResolveStartImportIdForQueuedReturnsLastImportId(): void
    {
        $item = new ImportBatchRunItem(10, 'B');
        $item->setStatus(ImportBatchRunItemStatus::Queued);

        self::assertSame(10, $this->resolver->resolveStartImportId($item, 3));
    }

    private function runWithLastItem(int $importId, ImportBatchRunItemStatus $status, int $attemptCount): ImportBatchRun
    {
        $run = new ImportBatchRun([]);
        $item = new ImportBatchRunItem($importId, 'Test');
        $item->setStatus($status);
        for ($i = 0; $i < $attemptCount; ++$i) {
            $item->incrementAttemptCount();
        }
        $run->addItem($item);

        return $run;
    }
}
