<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Entity;

use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportExportPeriodSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Import::class)]
final class ImportExportPeriodTest extends TestCase
{
    #[Test]
    public function storesDerivedPeriodAndReportsChange(): void
    {
        $import = new Import();

        self::assertTrue($import->applyDerivedExportPeriod(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-04-01')));
        self::assertSame(ImportExportPeriodSource::DERIVED, $import->getExportPeriodSource());
        self::assertSame('2026-03-01', $import->getExportStartsAt()?->format('Y-m-d'));
        self::assertFalse($import->applyDerivedExportPeriod(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-04-01')));
    }

    #[Test]
    public function keepsManualPeriod(): void
    {
        $import = new Import();
        $import->setExportStartsAt(new \DateTimeImmutable('2026-02-01'))
            ->setExportEndsAt(new \DateTimeImmutable('2026-03-01'))
            ->setExportPeriodSource(ImportExportPeriodSource::MANUAL);

        self::assertFalse($import->applyDerivedExportPeriod(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-04-01')));
        self::assertSame('2026-02-01', $import->getExportStartsAt()?->format('Y-m-d'));
    }

    #[Test]
    public function marksImportsWithoutRowsAsDerived(): void
    {
        $import = new Import();

        self::assertTrue($import->applyDerivedExportPeriod(null, null));
        self::assertSame(ImportExportPeriodSource::DERIVED, $import->getExportPeriodSource());
        self::assertNull($import->getExportStartsAt());
        self::assertNull($import->getExportEndsAt());
    }
}
