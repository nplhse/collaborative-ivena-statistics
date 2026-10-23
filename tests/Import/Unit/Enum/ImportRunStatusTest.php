<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Enum;

use App\Import\Domain\Enum\ImportRunStatus;
use App\Import\Domain\Enum\ImportStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportRunStatusTest extends TestCase
{
    public function testOnlyQueuedAndRunningAreOpen(): void
    {
        $open = array_values(array_filter(
            ImportRunStatus::cases(),
            static fn (ImportRunStatus $status): bool => !$status->isTerminal(),
        ));

        self::assertSame([ImportRunStatus::Queued, ImportRunStatus::Running], $open);
        self::assertSame($open, ImportRunStatus::openStatuses());
    }

    #[DataProvider('finalImportStatusProvider')]
    public function testFromFinalImportStatus(ImportStatus $importStatus, ImportRunStatus $expected): void
    {
        self::assertSame($expected, ImportRunStatus::fromImportStatus($importStatus));
    }

    /**
     * @return iterable<string, array{ImportStatus, ImportRunStatus}>
     */
    public static function finalImportStatusProvider(): iterable
    {
        yield 'completed' => [ImportStatus::COMPLETED, ImportRunStatus::Completed];
        yield 'partial' => [ImportStatus::PARTIAL, ImportRunStatus::Partial];
        yield 'failed' => [ImportStatus::FAILED, ImportRunStatus::Failed];
        yield 'cancelled' => [ImportStatus::CANCELLED, ImportRunStatus::Failed];
    }

    public function testOpenImportStatusIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ImportRunStatus::fromImportStatus(ImportStatus::RUNNING);
    }
}
