<?php

declare(strict_types=1);

namespace App\Shared\Application\Export;

use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportOrchestrator
{
    public function __construct(
        private readonly ExporterRegistry $exporterRegistry,
        private readonly CsvStreamExportResponseFactory $csvStreamExportResponseFactory,
        private readonly ExportAuditLogger $exportAuditLogger,
    ) {
    }

    public function estimate(User $user, string $exporterKey, object $criteria): ExportEstimate
    {
        $exporter = $this->exporterRegistry->get($exporterKey);
        $exporter->assertCanExport($user);

        $count = $exporter->count($user, $criteria);
        $blocked = $count > ExportLimits::MAX_EXPORT_ROWS;
        $warn = !$blocked && $count >= ExportLimits::WARN_EXPORT_ROWS;

        if ($blocked) {
            $this->exportAuditLogger->log(
                $user,
                $exporterKey,
                $exporter->resolveScopeHospitalIds($user),
                $exporter->serializeCriteria($criteria),
                $count,
                true,
                false,
            );
        }

        return new ExportEstimate($count, $blocked, $warn, $exporterKey);
    }

    public function download(User $user, string $exporterKey, object $criteria): StreamedResponse
    {
        $exporter = $this->exporterRegistry->get($exporterKey);
        $exporter->assertCanExport($user);

        $count = $exporter->count($user, $criteria);
        if ($count > ExportLimits::MAX_EXPORT_ROWS) {
            $this->exportAuditLogger->log(
                $user,
                $exporterKey,
                $exporter->resolveScopeHospitalIds($user),
                $exporter->serializeCriteria($criteria),
                $count,
                true,
                false,
            );

            throw new ExportBlockedException($count, $exporterKey);
        }

        $hospitalIds = $exporter->resolveScopeHospitalIds($user);
        $filters = $exporter->serializeCriteria($criteria);

        $this->exportAuditLogger->log(
            $user,
            $exporterKey,
            $hospitalIds,
            $filters,
            $count,
            false,
            true,
        );

        return $this->csvStreamExportResponseFactory->create(
            $exporter->buildFilename(),
            static fn ($stream): int => $exporter->writeCsv($user, $criteria, $stream),
        );
    }
}
