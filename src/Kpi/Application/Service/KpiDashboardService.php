<?php

declare(strict_types=1);

namespace App\Kpi\Application\Service;

use App\Admin\UI\Http\Controller\Allocation\AllocationCrudController;
use App\Admin\UI\Http\Controller\Hospital\HospitalCrudController;
use App\Admin\UI\Http\Controller\Import\ImportCrudController;
use App\Admin\UI\Http\Controller\ImportReject\ImportRejectCrudController;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Kpi\Application\DTO\FailedImportRowDto;
use App\Kpi\Application\DTO\KpiCardDto;
use App\Kpi\Application\DTO\KpiCardsDto;
use App\Kpi\Application\DTO\KpiChartSeriesDto;
use App\Kpi\Infrastructure\Repository\KpiDailyRepository;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;

final readonly class KpiDashboardService
{
    private const string TIMEZONE = 'Europe/Berlin';

    public function __construct(
        private KpiDailyRepository $kpiDailyRepository,
        private ImportRepository $importRepository,
        private ImportFailureReasonResolver $failureReasonResolver,
        private AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public function getCards(): KpiCardsDto
    {
        $sums = $this->kpiDailyRepository->sumLast30DaysGlobal();
        $activeHospitals = $this->kpiDailyRepository->countActiveHospitalsLast30Days();
        $rejectionRate = KpiDailyRepository::calculateRejectionRate(
            $sums['recordsRejected'],
            $sums['recordsTotal'],
        );

        return new KpiCardsDto([
            new KpiCardDto(
                labelKey: 'kpi.card.active_hospitals',
                value: (string) $activeHospitals,
                detailUrl: $this->generateCrudUrl(HospitalCrudController::class),
                icon: 'fas fa-hospital',
            ),
            new KpiCardDto(
                labelKey: 'kpi.card.imports',
                value: (string) $sums['importsCount'],
                detailUrl: $this->generateCrudUrl(ImportCrudController::class),
                icon: 'fa fa-database',
            ),
            new KpiCardDto(
                labelKey: 'kpi.card.records_processed',
                value: number_format($sums['recordsProcessed'], 0, ',', '.'),
                detailUrl: $this->generateCrudUrl(AllocationCrudController::class),
                icon: 'fas fa-list-ol',
            ),
            new KpiCardDto(
                labelKey: 'kpi.card.rejection_rate',
                value: sprintf('%.2f%%', $rejectionRate),
                detailUrl: $this->generateCrudUrl(ImportRejectCrudController::class),
                icon: 'fas fa-chart-line',
            ),
        ]);
    }

    public function getChart(): KpiChartSeriesDto
    {
        $series = $this->kpiDailyRepository->getDailySeriesLast30DaysGlobal();
        $indexed = [];
        foreach ($series as $row) {
            $indexed[$row['date']->format('Y-m-d')] = $row;
        }

        $labels = [];
        $recordsPerDay = [];
        $rejectionRatePerDay = [];
        $tz = new \DateTimeZone(self::TIMEZONE);
        $today = new \DateTimeImmutable('today', $tz);

        for ($offset = 29; $offset >= 0; --$offset) {
            $day = $today->modify(sprintf('-%d days', $offset));
            $key = $day->format('Y-m-d');
            $labels[] = $day->format('d.m.');
            $row = $indexed[$key] ?? null;
            $records = $row['recordsProcessed'] ?? 0;
            $total = $row['recordsTotal'] ?? 0;
            $rejected = $row['recordsRejected'] ?? 0;
            $recordsPerDay[] = $records;
            $rejectionRatePerDay[] = KpiDailyRepository::calculateRejectionRate($rejected, $total);
        }

        return new KpiChartSeriesDto($labels, $recordsPerDay, $rejectionRatePerDay);
    }

    /**
     * @return list<FailedImportRowDto>
     */
    public function getRecentFailedImports(int $limit = 10): array
    {
        $imports = $this->importRepository->findRecentFailedImports($limit);
        $rows = [];

        foreach ($imports as $import) {
            if (!$import instanceof Import) {
                continue;
            }

            $hospital = $import->getHospital();
            $rows[] = new FailedImportRowDto(
                createdAt: $import->getCreatedAt(),
                hospitalName: $hospital?->getName() ?? '—',
                fileName: $import->getName() ?? '—',
                status: $import->getStatus() ?? ImportStatus::FAILED,
                failureReasonKey: $this->failureReasonResolver->resolve($import),
                recordCount: $import->getRowCount() ?? 0,
                rejectionCount: $import->getRowsRejected() ?? 0,
                detailUrl: $this->generateImportDetailUrl($import),
            );
        }

        return $rows;
    }

    /**
     * @param class-string $controller
     */
    private function generateCrudUrl(string $controller): string
    {
        return $this->adminUrlGenerator
            ->setController($controller)
            ->generateUrl();
    }

    private function generateImportDetailUrl(Import $import): ?string
    {
        $id = $import->getId();
        if (null === $id) {
            return null;
        }

        return $this->adminUrlGenerator
            ->setController(ImportCrudController::class)
            ->setAction('detail')
            ->setEntityId($id)
            ->generateUrl();
    }
}
