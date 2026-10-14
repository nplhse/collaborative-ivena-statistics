<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Service;

use App\Allocation\Domain\Entity\Hospital;
use App\Import\Application\Contracts\ImportSourceRangeReaderInterface;
use App\Import\Application\ExportPeriod\ExportPeriod;
use App\Import\Application\Service\ImportExportPeriodDeriver;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Statistics\Application\Contract\ClosureRebuildRequestInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImportExportPeriodDeriver::class)]
final class ImportExportPeriodDeriverTest extends TestCase
{
    #[Test]
    public function derivesAllocationPeriodFromAllocationRange(): void
    {
        $ranges = $this->createStub(ImportSourceRangeReaderInterface::class);
        $ranges->method('allocationRange')->willReturn([
            new \DateTimeImmutable('2026-03-01 00:10:00'),
            new \DateTimeImmutable('2026-03-31 22:00:00'),
        ]);

        $import = $this->import(ImportType::ALLOCATION);
        self::assertTrue($this->deriver($ranges)->apply($import));
        self::assertSame('2026-03-01 00:00', $import->getExportStartsAt()?->format('Y-m-d H:i'));
        self::assertSame('2026-04-01 00:00', $import->getExportEndsAt()?->format('Y-m-d H:i'));
    }

    #[Test]
    public function closurePeriodUsesStoredAllocationPeriodsOfTheHospital(): void
    {
        $ranges = $this->createMock(ImportSourceRangeReaderInterface::class);
        $ranges->method('closureRange')->willReturn([
            new \DateTimeImmutable('2026-03-04 08:00:00'),
            new \DateTimeImmutable('2026-03-30 12:00:00'),
        ]);
        $ranges->expects($this->once())->method('allocationExportPeriods')->with(9)->willReturn([
            new ExportPeriod(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-04-01')),
        ]);

        $period = $this->deriver($ranges)->derive($this->import(ImportType::CLOSURE, 9));

        self::assertNotNull($period);
        self::assertSame('2026-03-01', $period->startsAt->format('Y-m-d'));
        self::assertSame('2026-04-01', $period->endsAt->format('Y-m-d'));
    }

    #[Test]
    public function explicitAllocationPeriodsOverrideStoredOnes(): void
    {
        $ranges = $this->createMock(ImportSourceRangeReaderInterface::class);
        $ranges->method('closureRange')->willReturn([
            new \DateTimeImmutable('2026-03-04 08:00:00'),
            new \DateTimeImmutable('2026-03-30 12:00:00'),
        ]);
        $ranges->expects($this->never())->method('allocationExportPeriods');

        $period = $this->deriver($ranges)->derive($this->import(ImportType::CLOSURE, 9), []);

        self::assertNotNull($period);
        self::assertSame('2026-03-04', $period->startsAt->format('Y-m-d'));
        self::assertSame('2026-03-31', $period->endsAt->format('Y-m-d'));
    }

    private function deriver(ImportSourceRangeReaderInterface $ranges): ImportExportPeriodDeriver
    {
        return new ImportExportPeriodDeriver(
            $ranges,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(ClosureRebuildRequestInterface::class),
        );
    }

    private function import(ImportType $type, int $hospitalId = 1): Import
    {
        $hospital = $this->createStub(Hospital::class);
        $hospital->method('getId')->willReturn($hospitalId);
        $import = new Import();
        $import->setType($type);
        $import->setHospital($hospital);
        $reflection = new \ReflectionProperty(Import::class, 'id');
        $reflection->setValue($import, 5);

        return $import;
    }
}
