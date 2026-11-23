<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\MessageHandler;

use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;

/**
 * Fixture: one completed import with 1 allocation, then a second file of the same hospital that repeats this row.
 */
final class ImportAllocationsMessageHandlerFailedDeduplicationTest extends ImportAllocationsMessageHandlerTestCase
{
    public function testFailingImportLeavesMatchingRowsOfAnOlderImportInPlace(): void
    {
        ['id' => $olderId, 'csvPath' => $csvPath] = $this->arrangeSingleRowSuccessfulCsvImport();
        $this->process($olderId);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($olderId);

        // 1 valid + 1 rejected row: 1 / 2 = 50 % rejected, so the import fails.
        $failingId = $this->arrangeImportOfSameHospital($olderId, $this->csvWithRejectedRows($csvPath, 1));
        $this->process($failingId);

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
        $this->process($olderId);

        $newerId = $this->arrangeImportOfSameHospital($olderId, (string) file_get_contents($csvPath));
        $this->process($newerId);

        $this->em->clear();
        $newer = $this->imports->find($newerId);
        self::assertNotNull($newer);
        self::assertSame(ImportStatus::COMPLETED, $newer->getStatus());
        self::assertSame(1, $newer->getRowsDeduplicatedReplaced());
        self::assertSame(0, $this->countAllocationsForImport($olderId));
        self::assertSame(1, $this->countAllocationsForImport($newerId));
    }

    private function process(int $importId): void
    {
        $run = self::getContainer()->get(ImportRunLifecycle::class)->register($importId, ImportRunTrigger::Console);

        $this->handler->__invoke(new ImportAllocationsMessage($importId, $run->getId()->toRfc4122()));
    }

    private function csvWithRejectedRows(string $csvPath, int $rejectedRows): string
    {
        $lines = preg_split('/\r\n|\n/', rtrim((string) file_get_contents($csvPath))) ?: [];
        $validRow = (string) end($lines);
        $rejectedRow = str_replace('07.01.2025', 'not-a-date', $validRow);

        return implode("\n", [...$lines, ...array_fill(0, $rejectedRows, $rejectedRow)])."\n";
    }

    private function arrangeImportOfSameHospital(int $importId, string $contents): int
    {
        $source = $this->imports->find($importId);
        self::assertInstanceOf(Import::class, $source);

        ['absolutePath' => $path, 'storedPath' => $storedPath] = $this->writeImportCsvUnderBase(
            'ivena-import-second-'.bin2hex(random_bytes(8)).'.csv',
            $contents,
        );

        $import = new Import()
            ->setName('Second file of the hospital')
            ->setHospital($source->getHospital())
            ->setCreatedBy($source->getCreatedBy())
            ->setType(ImportType::ALLOCATION)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($storedPath)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize((int) filesize($path))
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0);

        $this->em->persist($import);
        $this->em->flush();

        return (int) $import->getId();
    }

    private function countProjectionRowsForImport(int $importId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM allocation_stats_projection WHERE import_id = ?',
            [$importId],
        );
    }
}
