<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Presenter\Coverage;

use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Domain\ValueObject\ImportDataCoverage;
use App\Import\Domain\ValueObject\ImportDataCoverageGap;

/**
 * Builds the data coverage card from the stored summary. No database access.
 *
 * Only allocation imports flag days without records as potential gaps: allocations are
 * expected every day, closures are not.
 */
final readonly class ImportCoverageViewFactory
{
    /** About one year plus padding; longer periods only show the summary. */
    public const int MAX_HEATMAP_DAYS = 400;

    /** A month label needs about three week columns of room. */
    private const int MIN_WEEKS_PER_MONTH_LABEL = 3;

    private const string DAY = 'd.m.Y';

    private const string TIMESTAMP = 'd.m.Y H:i';

    public function create(Import $import): ImportCoverageView
    {
        $coverage = $import->getDataCoverage();
        $state = $this->state($import, $coverage);
        if (ImportCoverageState::Available !== $state || !$coverage instanceof ImportDataCoverage) {
            return new ImportCoverageView($state);
        }

        $detectsGaps = ImportType::ALLOCATION === $import->getType();

        return new ImportCoverageView(
            state: $state,
            detectsGaps: $detectsGaps,
            records: $coverage->records,
            firstAt: $coverage->firstAt?->format(self::TIMESTAMP),
            lastAt: $coverage->lastAt?->format(self::TIMESTAMP),
            firstDay: $coverage->firstDay()?->format(self::DAY),
            lastDay: $coverage->lastDay()?->format(self::DAY),
            periodDays: $coverage->periodDays(),
            daysWithData: $coverage->daysWithData(),
            daysWithoutData: $coverage->daysWithoutData(),
            gaps: array_map(
                static fn (ImportDataCoverageGap $gap): ImportCoverageGapView => new ImportCoverageGapView(
                    $gap->firstDay->format(self::DAY),
                    $gap->lastDay->format(self::DAY),
                    $gap->days(),
                ),
                $coverage->gaps(),
            ),
            weeks: $coverage->periodDays() <= self::MAX_HEATMAP_DAYS ? $this->weeks($coverage, $detectsGaps) : [],
        );
    }

    private function state(Import $import, ?ImportDataCoverage $coverage): ImportCoverageState
    {
        return match ($import->getStatus()) {
            ImportStatus::PENDING, ImportStatus::RUNNING, null => ImportCoverageState::Pending,
            ImportStatus::FAILED, ImportStatus::CANCELLED => ImportCoverageState::Unavailable,
            ImportStatus::COMPLETED, ImportStatus::PARTIAL => match (true) {
                !$coverage instanceof ImportDataCoverage => ImportCoverageState::NotCalculated,
                $coverage->isEmpty() => ImportCoverageState::Empty,
                default => ImportCoverageState::Available,
            },
        };
    }

    /**
     * @return list<ImportCoverageWeek>
     */
    private function weeks(ImportDataCoverage $coverage, bool $detectsGaps): array
    {
        $first = $coverage->firstDay();
        $last = $coverage->lastDay();
        if (!$first instanceof \DateTimeImmutable || !$last instanceof \DateTimeImmutable) {
            return [];
        }
        $max = max(1, $coverage->maxDailyCount());
        /** @var list<array{days: list<ImportCoverageCell>, month: ?\DateTimeImmutable}> $columns */
        $columns = [];
        $days = [];
        $month = null;
        $day = $first->modify('monday this week');
        $end = $last->modify('sunday this week');
        while ($day <= $end) {
            $inRange = $day >= $first && $day <= $last;
            // A month is labelled above the week containing its first covered day.
            if ($inRange && ($day == $first || '01' === $day->format('d'))) {
                $month = $day->modify('first day of this month');
            }
            $count = $coverage->countOn($day);
            $days[] = match (true) {
                !$inRange => new ImportCoverageCell($day->format(self::DAY), ImportCoverageCell::OUTSIDE),
                $count > 0 => new ImportCoverageCell($day->format(self::DAY), ImportCoverageCell::DATA, $count, $this->level($count, $max)),
                default => new ImportCoverageCell($day->format(self::DAY), $detectsGaps ? ImportCoverageCell::GAP : ImportCoverageCell::NONE),
            };
            if (7 === \count($days)) {
                $columns[] = ['days' => $days, 'month' => $month];
                $days = [];
                $month = null;
            }
            $day = $day->modify('+1 day');
        }

        return $this->spreadMonthLabels($columns);
    }

    /**
     * @return int<1, 4>
     */
    private function level(int $count, int $max): int
    {
        return max(1, min(ImportCoverageCell::LEVELS, (int) ceil($count * ImportCoverageCell::LEVELS / $max)));
    }

    /**
     * Drops a month label that would overlap the next one, as the first month often starts late in a week.
     *
     * @param list<array{days: list<ImportCoverageCell>, month: ?\DateTimeImmutable}> $columns
     *
     * @return list<ImportCoverageWeek>
     */
    private function spreadMonthLabels(array $columns): array
    {
        $weeks = [];
        foreach ($columns as $index => $column) {
            $month = $column['month'];
            $until = min(\count($columns), $index + self::MIN_WEEKS_PER_MONTH_LABEL);
            for ($next = $index + 1; null !== $month && $next < $until; ++$next) {
                if (null !== $columns[$next]['month']) {
                    $month = null;
                }
            }
            $weeks[] = new ImportCoverageWeek($column['days'], $month);
        }

        return $weeks;
    }
}
