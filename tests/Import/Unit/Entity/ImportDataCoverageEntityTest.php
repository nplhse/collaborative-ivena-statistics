<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Entity;

use App\Import\Domain\Entity\Import;
use App\Import\Domain\ValueObject\ImportDataCoverage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Import::class)]
final class ImportDataCoverageEntityTest extends TestCase
{
    #[Test]
    public function isNotCalculatedUntilApplied(): void
    {
        self::assertNull(new Import()->getDataCoverage());
    }

    #[Test]
    public function storesCoverageAndReportsChange(): void
    {
        $import = new Import();
        $coverage = new ImportDataCoverage(
            2,
            new \DateTimeImmutable('2026-09-01 08:00:00'),
            new \DateTimeImmutable('2026-09-03 09:00:00'),
            ['2026-09-01' => 1, '2026-09-03' => 1],
        );

        self::assertTrue($import->applyDataCoverage($coverage));
        self::assertEquals($coverage, $import->getDataCoverage());
        self::assertFalse($import->applyDataCoverage($coverage));
        self::assertTrue($import->applyDataCoverage(ImportDataCoverage::empty()));
        self::assertTrue($import->getDataCoverage()?->isEmpty());
        self::assertTrue($import->applyDataCoverage(null));
        self::assertNull($import->getDataCoverage());
    }
}
