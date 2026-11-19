<?php

declare(strict_types=1);

namespace App\Kpi\Application\Service;

use App\Kpi\Application\Contract\AdminLinkGeneratorInterface;
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
    /** Periods of the admin KPI row in days; the first one is the default. */
    public const array PERIODS = [30, 90, 365];

    public function __construct(
        private KpiDailyRepository $kpiDailyRepository,
        private AdminLinkGeneratorInterface $adminLinkGenerator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * A period from the query string, falling back to the default for anything that is not one of PERIODS.
     */
    public static function periodFromQuery(string $value): int
    {
        return \in_array((int) $value, self::PERIODS, true) ? (int) $value : self::PERIODS[0];
    }

    /**
     * The KPI cards for the last `$days` Berlin calendar days including today, each compared with the `$days` days
     * before. Definitions: docs/04-features/kpi/kpi-aggregation.md#admin-kpi-row.
     */
    public function getCards(int $days = self::PERIODS[0]): KpiCardsDto
    {
        [$from, $toExclusive] = $this->period($days);
        $previousFrom = $from->modify(sprintf('-%d days', $days));

        $current = $this->kpiDailyRepository->globalTotalsInRange($from, $toExclusive);
        $previous = $this->kpiDailyRepository->globalTotalsInRange($previousFrom, $from);
        $activeHospitals = $this->kpiDailyRepository->countActiveParticipatingHospitalsInRange($from, $toExclusive);
        $previousActiveHospitals = $this->kpiDailyRepository->countActiveParticipatingHospitalsInRange($previousFrom, $from);

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
                    'participating' => $this->kpiDailyRepository->countParticipatingHospitals(),
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
     * @param array{recordsTotal: int, recordsRejected: int, ...} $totals
     */
    private function rejectionRate(array $totals): ?float
    {
        if ($totals['recordsTotal'] <= 0) {
            return null;
        }

        return KpiDailyRepository::calculateRejectionRate($totals['recordsRejected'], $totals['recordsTotal']);
    }

    /**
     * Imported and rejected records and the rejection rate per day of the last `$days` days including today.
     * The rate is null on days without rows read.
     */
    public function getChart(int $days = self::PERIODS[0]): KpiChartSeriesDto
    {
        [$from, $toExclusive] = $this->period($days);
        $indexed = [];
        foreach ($this->kpiDailyRepository->getDailySeriesGlobal($from, $toExclusive) as $row) {
            $indexed[$row['date']->format('Y-m-d')] = $row;
        }

        $labels = $imported = $rejected = $rates = [];
        for ($day = $from; $day < $toExclusive; $day = $day->modify('+1 day')) {
            $row = $indexed[$day->format('Y-m-d')] ?? ['recordsTotal' => 0, 'recordsRejected' => 0];
            $labels[] = $day->format(365 === $days ? 'd.m.y' : 'd.m.');
            $imported[] = $row['recordsTotal'] - $row['recordsRejected'];
            $rejected[] = $row['recordsRejected'];
            $rates[] = $this->rejectionRate($row);
        }

        return new KpiChartSeriesDto($labels, $imported, $rejected, $rates);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} first day and the day after the last
     */
    private function period(int $days): array
    {
        $today = $this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE))->modify('today');

        return [$today->modify(sprintf('-%d days', $days - 1)), $today->modify('+1 day')];
    }
}
