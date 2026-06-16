<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Service;

use App\Import\Application\DTO\ImportSummary;
use App\Import\Application\Service\ImportAllocationDeduplicationService;
use App\Import\Domain\Entity\Import;
use App\Statistics\Application\Projection\AllocationProjectionDeduplicator;
use App\Statistics\Application\Projection\Dto\DeduplicationReport;
use App\Statistics\Application\Projection\Dto\DeduplicationResult;
use App\Statistics\Application\Projection\Dto\DeduplicationStrategySummary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ImportAllocationDeduplicationServiceTest extends TestCase
{
    public function testDeduplicateForImportRequiresPersistedImportWithHospital(): void
    {
        $service = $this->createService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Import must be persisted with a hospital before deduplication.');

        $service->deduplicateForImport(new Import());
    }

    public function testDeduplicateForImportRequiresHospitalWhenImportHasId(): void
    {
        $import = $this->createStub(Import::class);
        $import->method('getId')->willReturn(42);
        $import->method('getHospital')->willReturn(null);

        $service = $this->createService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Import must be persisted with a hospital before deduplication.');

        $service->deduplicateForImport($import);
    }

    #[DataProvider('adjustSummaryProvider')]
    public function testAdjustSummaryReducesPassedRowsFromCurrentImportDeletionsOnly(
        ImportSummary $summary,
        DeduplicationResult $result,
        ImportSummary $expected,
    ): void {
        $adjusted = $this->createService()->adjustSummary($summary, $result);

        self::assertSame($expected->total, $adjusted->total);
        self::assertSame($expected->ok, $adjusted->ok);
        self::assertSame($expected->rejected, $adjusted->rejected);
    }

    public function testApplyDeduplicationStatsWritesAllDeduplicationCounters(): void
    {
        $import = new Import();
        $result = $this->createDeduplicationResult(
            deletedAllocations: 5,
            deletedFromCurrentImport: 2,
            deletedFromOtherImports: 3,
        );

        $service = $this->createService();

        $service->applyDeduplicationStats($import, $result);

        self::assertSame(5, $import->getRowsDeduplicated());
        self::assertSame(2, $import->getRowsDeduplicatedDiscarded());
        self::assertSame(3, $import->getRowsDeduplicatedReplaced());
    }

    /**
     * @return iterable<string, array{ImportSummary, DeduplicationResult, ImportSummary}>
     */
    public static function adjustSummaryProvider(): iterable
    {
        yield 'no current-import deletions' => [
            new ImportSummary(total: 10, ok: 8, rejected: 2),
            self::createDeduplicationResultStatic(deletedFromCurrentImport: 0),
            new ImportSummary(total: 10, ok: 8, rejected: 2),
        ];

        yield 'subtracts current-import deletions from passed rows' => [
            new ImportSummary(total: 10, ok: 8, rejected: 2),
            self::createDeduplicationResultStatic(deletedFromCurrentImport: 3),
            new ImportSummary(total: 10, ok: 5, rejected: 2),
        ];

        yield 'clamps passed rows at zero' => [
            new ImportSummary(total: 10, ok: 2, rejected: 8),
            self::createDeduplicationResultStatic(deletedFromCurrentImport: 5),
            new ImportSummary(total: 10, ok: 0, rejected: 8),
        ];
    }

    private function createService(): ImportAllocationDeduplicationService
    {
        $logger = $this->createStub(LoggerInterface::class);

        return new ImportAllocationDeduplicationService(
            new AllocationProjectionDeduplicator(
                $this->createStub(\Doctrine\DBAL\Connection::class),
                $logger,
            ),
            $logger,
        );
    }

    private function createDeduplicationResult(
        int $deletedAllocations = 0,
        int $deletedFromCurrentImport = 0,
        int $deletedFromOtherImports = 0,
    ): DeduplicationResult {
        return self::createDeduplicationResultStatic(
            deletedAllocations: $deletedAllocations,
            deletedFromCurrentImport: $deletedFromCurrentImport,
            deletedFromOtherImports: $deletedFromOtherImports,
        );
    }

    private static function createDeduplicationResultStatic(
        int $deletedAllocations = 0,
        int $deletedFromCurrentImport = 0,
        int $deletedFromOtherImports = 0,
    ): DeduplicationResult {
        $emptyStrategy = new DeduplicationStrategySummary('test', 0, 0, []);

        return new DeduplicationResult(
            report: new DeduplicationReport(
                enr: $emptyStrategy,
                fingerprint: $emptyStrategy,
                orphanProjectionRows: 0,
            ),
            deletedProjections: 0,
            deletedAllocations: $deletedAllocations,
            deletedAssessments: 0,
            deletedOrphanProjections: 0,
            deletedFromCurrentImport: $deletedFromCurrentImport,
            deletedFromOtherImports: $deletedFromOtherImports,
        );
    }
}
