<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Domain\Service;

use App\Import\Application\DTO\ImportSummary;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Service\ImportEvaluation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportEvaluationTest extends TestCase
{
    #[DataProvider('completedSummaryProvider')]
    public function testCompletedWhenRejectionRateIsBelowCompleteThreshold(ImportSummary $summary): void
    {
        $import = new Import();

        ImportEvaluation::apply($import, $summary, 1200);

        self::assertSame(ImportStatus::COMPLETED, $import->getStatus());
        self::assertSame($summary->total, $import->getRowCount());
        self::assertSame($summary->ok, $import->getRowsPassed());
        self::assertSame($summary->rejected, $import->getRowsRejected());
        self::assertSame(1, $import->getRunCount());
        self::assertSame(1200, $import->getRunTime());
    }

    #[DataProvider('partialSummaryProvider')]
    public function testPartialWhenRejectionRateIsBetweenCompleteAndFailedThreshold(ImportSummary $summary): void
    {
        $import = new Import();

        ImportEvaluation::apply($import, $summary, 800);

        self::assertSame(ImportStatus::PARTIAL, $import->getStatus());
        self::assertSame($summary->total, $import->getRowCount());
        self::assertSame($summary->ok, $import->getRowsPassed());
        self::assertSame($summary->rejected, $import->getRowsRejected());
        self::assertSame(1, $import->getRunCount());
        self::assertSame(800, $import->getRunTime());
    }

    public function testFailedDueToHighRejectionRatePersistsRowStatistics(): void
    {
        $import = new Import();

        ImportEvaluation::apply($import, new ImportSummary(total: 713, ok: 231, rejected: 482), 2909);

        self::assertSame(ImportStatus::FAILED, $import->getStatus());
        self::assertSame(713, $import->getRowCount());
        self::assertSame(231, $import->getRowsPassed());
        self::assertSame(482, $import->getRowsRejected());
        self::assertSame(1, $import->getRunCount());
        self::assertSame(2909, $import->getRunTime());
    }

    public function testFailedDueToEmptyFilePersistsZeroRowStatistics(): void
    {
        $import = new Import();

        ImportEvaluation::apply($import, ImportSummary::empty(), 100);

        self::assertSame(ImportStatus::FAILED, $import->getStatus());
        self::assertSame(0, $import->getRowCount());
        self::assertSame(0, $import->getRowsPassed());
        self::assertSame(0, $import->getRowsRejected());
    }

    /**
     * @return iterable<string, array{ImportSummary}>
     */
    public static function completedSummaryProvider(): iterable
    {
        yield 'no rejects' => [new ImportSummary(total: 100, ok: 100, rejected: 0)];
        yield 'below complete threshold' => [new ImportSummary(total: 100, ok: 96, rejected: 4)];
    }

    /**
     * @return iterable<string, array{ImportSummary}>
     */
    public static function partialSummaryProvider(): iterable
    {
        yield 'at complete threshold' => [new ImportSummary(total: 100, ok: 95, rejected: 5)];
        yield 'below failed threshold' => [new ImportSummary(total: 100, ok: 70, rejected: 30)];
        yield 'just below failed threshold' => [new ImportSummary(total: 1000, ok: 651, rejected: 349)];
    }
}
