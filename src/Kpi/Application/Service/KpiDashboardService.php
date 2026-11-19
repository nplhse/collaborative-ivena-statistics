<?php

declare(strict_types=1);

namespace App\Kpi\Application\Service;

use App\Kpi\Application\Contract\AdminLinkGeneratorInterface;
use App\Kpi\Application\Contract\KpiPeriodReaderInterface;
use App\Kpi\Application\DTO\KpiCardDto;
use App\Kpi\Application\DTO\KpiCardsDto;
use App\Kpi\Application\DTO\KpiChangeDto;
use App\Kpi\Application\DTO\KpiChartSeriesDto;
use App\Kpi\Infrastructure\Repository\KpiDailyRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class KpiDashboardService
{
    private const string TIMEZONE = 'Europe/Berlin';
    private const int PERIOD_DAYS = 30;

    public function __construct(
        private KpiDailyRepository $kpiDailyRepository,
        private AdminLinkGeneratorInterface $adminLinkGenerator,
        private ClockInterface $clock,
        private KpiPeriodReaderInterface $periodQuery,
    ) {
    }

    /**
     * The KPI cards for the last 30 Berlin calendar days including today, each compared with the 30 days before.
     * Definitions: docs/04-features/kpi/kpi-aggregation.md#admin-kpi-row.
     */
    public function getCards(): KpiCardsDto
    {
        $today = $this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE))->modify('today');
        $from = $today->modify(sprintf('-%d days', self::PERIOD_DAYS - 1));
        $toExclusive = $today->modify('+1 day');
        $previousFrom = $from->modify(sprintf('-%d days', self::PERIOD_DAYS));

        $current = $this->periodQuery->globalTotals($from, $toExclusive);
        $previous = $this->periodQuery->globalTotals($previousFrom, $from);
        $activeHospitals = $this->periodQuery->countActiveParticipatingHospitals($from, $toExclusive);
        $previousActiveHospitals = $this->periodQuery->countActiveParticipatingHospitals($previousFrom, $from);

        $imported = $current['recordsTotal'] - $current['recordsRejected'];
        $previousImported = $previous['recordsTotal'] - $previous['recordsRejected'];
        $rejectionRate = $this->rejectionRate($current);
        $previousRejectionRate = $this->rejectionRate($previous);

        return new KpiCardsDto([
            new KpiCardDto(
                label: new TranslatableMessage('kpi.card.active_hospitals', domain: 'admin'),
                value: $activeHospitals,
                detailUrl: $this->adminLinkGenerator->hospitalCrudIndexUrl(),
                icon: 'fas fa-hospital',
                detail: new TranslatableMessage('kpi.card.active_hospitals.detail', [
                    'participating' => $this->periodQuery->countParticipatingHospitals(),
                ], 'admin'),
                change: new KpiChangeDto((float) ($activeHospitals - $previousActiveHospitals), KpiChangeDto::UNIT_COUNT),
            ),
            new KpiCardDto(
                label: new TranslatableMessage('kpi.card.records_imported', domain: 'admin'),
                value: $imported,
                detailUrl: $this->adminLinkGenerator->importCrudIndexUrl(),
                icon: 'fa fa-database',
                detail: new TranslatableMessage('kpi.card.records_imported.detail', [
                    'total' => $current['recordsTotal'],
                    'imports' => $current['importsCount'],
                ], 'admin'),
                change: new KpiChangeDto(self::percentChange($imported, $previousImported), KpiChangeDto::UNIT_PERCENT),
            ),
            new KpiCardDto(
                label: new TranslatableMessage('kpi.card.rejection_rate', domain: 'admin'),
                value: $rejectionRate,
                detailUrl: $this->adminLinkGenerator->importRejectCrudIndexUrl(),
                icon: 'fas fa-chart-line',
                isPercent: true,
                detail: new TranslatableMessage('kpi.card.rejection_rate.detail', [
                    'rejected' => $current['recordsRejected'],
                    'total' => $current['recordsTotal'],
                ], 'admin'),
                change: new KpiChangeDto(
                    null === $rejectionRate || null === $previousRejectionRate ? null : round($rejectionRate - $previousRejectionRate, 2),
                    KpiChangeDto::UNIT_POINTS,
                    lowerIsBetter: true,
                ),
            ),
        ]);
    }

    /**
     * Relative change in percent, rounded to one decimal; null when the previous period is zero.
     */
    public static function percentChange(int $current, int $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round((float) ($current - $previous) / (float) $previous * 100.0, 1);
    }

    /**
     * @param array{importsCount: int, recordsTotal: int, recordsRejected: int} $totals
     */
    private function rejectionRate(array $totals): ?float
    {
        if ($totals['recordsTotal'] <= 0) {
            return null;
        }

        return KpiDailyRepository::calculateRejectionRate($totals['recordsRejected'], $totals['recordsTotal']);
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
