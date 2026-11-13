<?php

declare(strict_types=1);

namespace App\Kpi\Application\Service;

use App\Kpi\Application\Contract\AdminLinkGeneratorInterface;
use App\Kpi\Application\DTO\KpiCardDto;
use App\Kpi\Application\DTO\KpiCardsDto;
use App\Kpi\Application\DTO\KpiChartSeriesDto;
use App\Kpi\Infrastructure\Repository\KpiDailyRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class KpiDashboardService
{
    private const string TIMEZONE = 'Europe/Berlin';

    public function __construct(
        private KpiDailyRepository $kpiDailyRepository,
        private AdminLinkGeneratorInterface $adminLinkGenerator,
        private ClockInterface $clock,
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
                detailUrl: $this->adminLinkGenerator->hospitalCrudIndexUrl(),
                icon: 'fas fa-hospital',
            ),
            new KpiCardDto(
                label: new TranslatableMessage('kpi.card.imports', domain: 'admin'),
                value: (string) $sums['importsCount'],
                detailUrl: $this->adminLinkGenerator->importCrudIndexUrl(),
                icon: 'fa fa-database',
            ),
            new KpiCardDto(
                label: new TranslatableMessage('kpi.card.records_processed', domain: 'admin'),
                value: number_format($sums['recordsProcessed'], 0, ',', '.'),
                detailUrl: $this->adminLinkGenerator->allocationCrudIndexUrl(),
                icon: 'fas fa-list-ol',
            ),
            new KpiCardDto(
                label: new TranslatableMessage('kpi.card.rejection_rate', domain: 'admin'),
                value: sprintf('%.2f%%', $rejectionRate),
                detailUrl: $this->adminLinkGenerator->importRejectCrudIndexUrl(),
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
        $today = $this->clock->now()->setTimezone($tz)->modify('today');

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
}
