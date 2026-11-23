<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\MessageHandler;

use App\Import\Domain\Enum\ImportStatus;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;

/**
 * Fixture: one completed import with 1 allocation, then a second file of the same hospital that repeats this row.
 */
final class ImportAllocationsMessageHandlerFailedDeduplicationTest extends ImportAllocationsMessageHandlerTestCase
{
    public function testFailingImportLeavesMatchingRowsOfAnOlderImportInPlace(): void
    {
        ['id' => $olderId, 'csvPath' => $csvPath] = $this->arrangeSingleRowSuccessfulCsvImport();
        $this->processInNewRun($olderId);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($olderId);

        // 1 valid + 1 rejected row: 1 / 2 = 50 % rejected, so the import fails.
        $failingId = $this->arrangeImportOfSameHospital($olderId, $this->csvWithRejectedRows($csvPath, 1));
        $this->processInNewRun($failingId);

        $this->em->clear();
        $failing = $this->imports->find($failingId);
        self::assertNotNull($failing);
        self::assertSame(ImportStatus::FAILED, $failing->getStatus());
        self::assertSame(2, $failing->getRowCount());
        self::assertSame(1, $failing->getRowsPassed());
        self::assertSame(1, $failing->getRowsRejected());
        self::assertSame(0, $failing->getRowsDeduplicated() ?? 0);
        self::assertSame(0, $failing->getRowsDeduplicatedReplaced() ?? 0);

        self::assertSame(1, $this->countAllocationsForImport($olderId));
        self::assertSame(1, $this->countProjectionRowsForImport($olderId));
    }

    public function testSuccessfulImportStillReplacesMatchingRowsOfAnOlderImport(): void
    {
        ['id' => $olderId, 'csvPath' => $csvPath] = $this->arrangeSingleRowSuccessfulCsvImport();
        $this->processInNewRun($olderId);

        $newerId = $this->arrangeImportOfSameHospital($olderId, (string) file_get_contents($csvPath));
        $this->processInNewRun($newerId);

        $this->em->clear();
        $newer = $this->imports->find($newerId);
        self::assertNotNull($newer);
        self::assertSame(ImportStatus::COMPLETED, $newer->getStatus());
        self::assertSame(1, $newer->getRowsDeduplicatedReplaced());
        self::assertSame(0, $this->countAllocationsForImport($olderId));
        self::assertSame(1, $this->countAllocationsForImport($newerId));
    }
}
