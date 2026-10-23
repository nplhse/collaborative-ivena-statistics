<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Domain\Entity;

use App\Import\Domain\Entity\ImportBatchRun;
use App\Import\Domain\Entity\ImportBatchRunItem;
use App\Import\Domain\Enum\ImportBatchRunItemStatus;
use App\Import\Domain\Enum\ImportBatchRunStatus;
use App\Import\Domain\Enum\ImportRunStatus;
use PHPUnit\Framework\TestCase;

final class ImportBatchRunTest extends TestCase
{
    public function testClosingTheDispatchFinishesTheDispatchPhaseOnly(): void
    {
        $batch = new ImportBatchRun();
        $at = new \DateTimeImmutable('2026-10-22 20:00:00');

        $batch->closeDispatch($at);

        self::assertSame(ImportBatchRunStatus::Finished, $batch->getStatus());
        self::assertSame($at, $batch->getFinishedAt());
        self::assertSame($at, $batch->getDispatchClosedAt());
        self::assertNull($batch->getProcessingFinishedAt(), 'processing ends when the last item is done');
    }

    public function testItemsMirrorTheOutcomeOfTheirRun(): void
    {
        $item = new ImportBatchRunItem(1);
        $at = new \DateTimeImmutable('2026-10-22 20:00:00');

        $item->markFinishedBy(ImportRunStatus::Partial, $at);

        self::assertSame(ImportBatchRunItemStatus::Partial, $item->getStatus());
        self::assertSame($at, $item->getFinishedAt());
        self::assertSame(ImportBatchRunItemStatus::Superseded, ImportBatchRunItemStatus::fromRunStatus(ImportRunStatus::Superseded));
    }

    public function testOpenRunStatusesHaveNoItemOutcome(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ImportBatchRunItemStatus::fromRunStatus(ImportRunStatus::Running);
    }
}
