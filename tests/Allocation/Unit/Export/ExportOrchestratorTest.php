<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Export;

use App\Allocation\Application\Export\DTO\OwnHospitalAllocationsExportFilter;
use App\Allocation\Application\Export\OwnHospitalAllocationsExporter;
use App\Shared\Application\Export\ExporterInterface;
use App\Shared\Application\Export\ExporterRegistry;
use App\Shared\Application\Export\ExportLimits;
use App\Shared\Application\Export\ExportOrchestrator;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class ExportOrchestratorTest extends TestCase
{
    public function testEstimateBlocksAboveMaxRows(): void
    {
        $user = new User();
        $criteria = $this->createFilter();
        $exporter = $this->createExporterMock(count: ExportLimits::MAX_EXPORT_ROWS + 1);

        $orchestrator = $this->createOrchestrator($exporter);

        $estimate = $orchestrator->estimate($user, OwnHospitalAllocationsExporter::KEY, $criteria);

        self::assertTrue($estimate->blocked);
        self::assertFalse($estimate->warn);
    }

    public function testEstimateWarnsBetweenThresholds(): void
    {
        $user = new User();
        $criteria = $this->createFilter();
        $exporter = $this->createExporterMock(count: ExportLimits::WARN_EXPORT_ROWS);

        $orchestrator = $this->createOrchestrator($exporter);

        $estimate = $orchestrator->estimate($user, OwnHospitalAllocationsExporter::KEY, $criteria);

        self::assertFalse($estimate->blocked);
        self::assertTrue($estimate->warn);
    }

    public function testEstimateAllowsBelowWarnThreshold(): void
    {
        $user = new User();
        $criteria = $this->createFilter();
        $exporter = $this->createExporterMock(count: ExportLimits::WARN_EXPORT_ROWS - 1);

        $orchestrator = $this->createOrchestrator($exporter);

        $estimate = $orchestrator->estimate($user, OwnHospitalAllocationsExporter::KEY, $criteria);

        self::assertFalse($estimate->blocked);
        self::assertFalse($estimate->warn);
    }

    private function createOrchestrator(ExporterInterface $exporter): ExportOrchestrator
    {
        return new ExportOrchestrator(
            new ExporterRegistry([$exporter]),
            new \App\Shared\Application\Export\CsvStreamExportResponseFactory(),
            $this->createMock(\App\Shared\Application\Export\ExportAuditLogger::class),
        );
    }

    private function createExporterMock(int $count): ExporterInterface
    {
        $exporter = $this->createMock(ExporterInterface::class);
        $exporter->method('key')->willReturn(OwnHospitalAllocationsExporter::KEY);
        $exporter->method('count')->willReturn($count);
        $exporter->method('resolveScopeHospitalIds')->willReturn([1]);
        $exporter->method('serializeCriteria')->willReturn([]);

        return $exporter;
    }

    private function createFilter(): OwnHospitalAllocationsExportFilter
    {
        return new OwnHospitalAllocationsExportFilter(
            dateFrom: new \DateTimeImmutable('2026-01-01'),
            dateTo: new \DateTimeImmutable('2026-01-31'),
        );
    }
}
