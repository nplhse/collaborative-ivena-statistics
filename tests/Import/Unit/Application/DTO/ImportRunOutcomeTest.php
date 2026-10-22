<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\DTO;

use App\Import\Application\DTO\ImportRunOutcome;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunReasonCode;
use App\Import\Domain\Enum\ImportRunStatus;
use PHPUnit\Framework\TestCase;

final class ImportRunOutcomeTest extends TestCase
{
    public function testCompletedImportHasCountsAndNoReasonCode(): void
    {
        $import = new Import();
        $import->markAsCompleted(total: 10, ok: 10, rejected: 0, runtimeMs: 5);

        $outcome = ImportRunOutcome::fromEvaluatedImport($import);

        self::assertSame(ImportRunStatus::Completed, $outcome->status);
        self::assertSame(10, $outcome->rowsTotal);
        self::assertSame(10, $outcome->rowsOk);
        self::assertSame(0, $outcome->rowsRejected);
        self::assertNull($outcome->reasonCode);
    }

    public function testPartialImportIsPartialRun(): void
    {
        $import = new Import();
        $import->markAsPartial(total: 10, ok: 8, rejected: 2, runtimeMs: 5);

        self::assertSame(ImportRunStatus::Partial, ImportRunOutcome::fromEvaluatedImport($import)->status);
    }

    public function testFailedImportWithoutRowsHasNoRowsReason(): void
    {
        $import = new Import();
        $import->markAsFailed(runtimeMs: 5);

        $outcome = ImportRunOutcome::fromEvaluatedImport($import);

        self::assertSame(ImportRunStatus::Failed, $outcome->status);
        self::assertSame(ImportRunReasonCode::NoRows, $outcome->reasonCode);
    }

    public function testFailedImportWithRowsExceededTheRejectionRatio(): void
    {
        $import = new Import();
        $import->markAsFailed(runtimeMs: 5, total: 10, ok: 2, rejected: 8);

        self::assertSame(ImportRunReasonCode::RejectionRatioExceeded, ImportRunOutcome::fromEvaluatedImport($import)->reasonCode);
    }

    public function testFailedOutcomeCarriesOnlyTheReasonCode(): void
    {
        $outcome = ImportRunOutcome::failed(ImportRunReasonCode::FileNotFound);

        self::assertSame(ImportRunStatus::Failed, $outcome->status);
        self::assertSame(ImportRunReasonCode::FileNotFound, $outcome->reasonCode);
        self::assertNull($outcome->rowsTotal);
    }

    public function testImportWithoutStatusIsRejected(): void
    {
        $this->expectException(\LogicException::class);

        ImportRunOutcome::fromEvaluatedImport(new Import());
    }

    public function testOpenStatusIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ImportRunOutcome(ImportRunStatus::Running);
    }
}
