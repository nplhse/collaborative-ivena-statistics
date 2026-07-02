<?php

declare(strict_types=1);

namespace App\Kpi\Application\Service;

use App\Admin\UI\Http\Controller\Allocation\AllocationCrudController;
use App\Admin\UI\Http\Controller\DashboardController;
use App\Admin\UI\Http\Controller\Hospital\HospitalCrudController;
use App\Admin\UI\Http\Controller\Import\ImportCrudController;
use App\Admin\UI\Http\Controller\ImportReject\ImportRejectCrudController;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Kpi\Application\DTO\FailedImportRowDto;
use App\Kpi\Application\DTO\FailedImportsDashboardDto;
use App\Kpi\Application\DTO\KpiCardDto;
use App\Kpi\Application\DTO\KpiCardsDto;
use App\Kpi\Application\DTO\KpiChartSeriesDto;
use App\Kpi\Infrastructure\Repository\KpiDailyRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\ComparisonType;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;

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
                label: new TranslatableMessage('kpi.card.active_hospitals', domain: 'admin'),
                value: (string) $activeHospitals,
                detailUrl: $this->generateCrudUrl(HospitalCrudController::class),
                icon: 'fas fa-hospital',
            ),
            new KpiCardDto(
                label: new TranslatableMessage('kpi.card.imports', domain: 'admin'),
                value: (string) $sums['importsCount'],
                detailUrl: $this->generateCrudUrl(ImportCrudController::class),
                icon: 'fa fa-database',
            ),
            new KpiCardDto(
                label: new TranslatableMessage('kpi.card.records_processed', domain: 'admin'),
                value: number_format($sums['recordsProcessed'], 0, ',', '.'),
                detailUrl: $this->generateCrudUrl(AllocationCrudController::class),
                icon: 'fas fa-list-ol',
            ),
            new KpiCardDto(
                label: new TranslatableMessage('kpi.card.rejection_rate', domain: 'admin'),
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

    public function getFailedImportsDashboard(int $limit = 10): FailedImportsDashboardDto
    {
        $since = new \DateTimeImmutable('-30 days', new \DateTimeZone(self::TIMEZONE))
            ->setTime(0, 0);

        return new FailedImportsDashboardDto(
            rows: $this->buildFailedImportRows(
                $this->importRepository->findRecentFailedImports($limit, $since),
            ),
            totalFailedCount: $this->importRepository->countFailedImports(),
            allFailedImportsUrl: $this->generateFailedImportsIndexUrl(),
        );
    }

    /**
     * @param list<Import> $imports
     *
     * @return list<FailedImportRowDto>
     */
    private function buildFailedImportRows(array $imports): array
    {
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

    private function generateFailedImportsIndexUrl(): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setDashboard(DashboardController::class)
            ->setController(ImportCrudController::class)
            ->setAction(Action::INDEX)
            ->set(EA::FILTERS, [
                'status' => [
                    'comparison' => ComparisonType::EQ,
                    'value' => ImportStatus::FAILED->value,
                ],
            ])
            ->generateUrl();
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
