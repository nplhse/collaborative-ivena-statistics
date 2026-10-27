<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Service;

use App\Import\Application\Contracts\ImportCoverageReaderInterface;
use App\Import\Application\Service\ImportDataCoverageCalculator;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Import\Domain\ValueObject\ImportDataCoverage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImportDataCoverageCalculator::class)]
final class ImportDataCoverageCalculatorTest extends TestCase
{
    #[Test]
    public function readsAllocationCoverageForAllocationImports(): void
    {
        $coverage = $this->coverage('2026-09-01');
        $reader = $this->createMock(ImportCoverageReaderInterface::class);
        $reader->expects($this->once())->method('allocationCoverage')->with(7)->willReturn($coverage);
        $reader->expects($this->never())->method('closureCoverage');
        $import = $this->import(ImportType::ALLOCATION);

        new ImportDataCoverageCalculator($reader)->apply($import);
        self::assertEquals($coverage, $import->getDataCoverage());
    }

    #[Test]
    public function readsClosureCoverageForClosureImports(): void
    {
        $coverage = $this->coverage('2026-09-04');
        $reader = $this->createMock(ImportCoverageReaderInterface::class);
        $reader->expects($this->once())->method('closureCoverage')->with(7)->willReturn($coverage);
        $reader->expects($this->never())->method('allocationCoverage');

        self::assertEquals($coverage, new ImportDataCoverageCalculator($reader)->calculate($this->import(ImportType::CLOSURE)));
    }

    #[Test]
    public function skipsUnsavedImports(): void
    {
        $reader = $this->createMock(ImportCoverageReaderInterface::class);
        $reader->expects($this->never())->method('allocationCoverage');

        self::assertNull(new ImportDataCoverageCalculator($reader)->calculate(new Import()->setType(ImportType::ALLOCATION)));
    }

    private function import(ImportType $type): Import
    {
        $import = new Import()->setType($type);
        new \ReflectionProperty(Import::class, 'id')->setValue($import, 7);

        return $import;
    }

    private function coverage(string $day): ImportDataCoverage
    {
        return new ImportDataCoverage(1, new \DateTimeImmutable($day.' 10:00:00'), new \DateTimeImmutable($day.' 11:00:00'), [$day => 1]);
    }
}
