<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\UI\Http\Presenter\Coverage;

use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Domain\ValueObject\ImportDataCoverage;
use App\Import\UI\Http\Presenter\Coverage\ImportCoverageCell;
use App\Import\UI\Http\Presenter\Coverage\ImportCoverageState;
use App\Import\UI\Http\Presenter\Coverage\ImportCoverageView;
use App\Import\UI\Http\Presenter\Coverage\ImportCoverageViewFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImportCoverageViewFactory::class)]
#[CoversClass(ImportCoverageView::class)]
final class ImportCoverageViewFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{ImportStatus, ?ImportDataCoverage, ImportCoverageState}>
     */
    public static function fallbacks(): iterable
    {
        $coverage = self::coverage(['2026-09-01' => 1]);

        yield 'pending' => [ImportStatus::PENDING, null, ImportCoverageState::Pending];
        yield 'running keeps an older summary hidden' => [ImportStatus::RUNNING, $coverage, ImportCoverageState::Pending];
        yield 'failed' => [ImportStatus::FAILED, $coverage, ImportCoverageState::Unavailable];
        yield 'cancelled' => [ImportStatus::CANCELLED, null, ImportCoverageState::Unavailable];
        yield 'not calculated' => [ImportStatus::COMPLETED, null, ImportCoverageState::NotCalculated];
        yield 'no records' => [ImportStatus::PARTIAL, ImportDataCoverage::empty(), ImportCoverageState::Empty];
    }

    #[Test]
    #[DataProvider('fallbacks')]
    public function showsAFallbackUnlessAFinishedImportHasRecords(ImportStatus $status, ?ImportDataCoverage $coverage, ImportCoverageState $expected): void
    {
        $view = new ImportCoverageViewFactory()->create($this->import(ImportType::ALLOCATION, $status, $coverage));

        self::assertSame($expected, $view->state);
        self::assertFalse($view->isAvailable());
        self::assertSame([], $view->weeks);
    }

    #[Test]
    public function allocationImportsListPotentialGaps(): void
    {
        $view = new ImportCoverageViewFactory()->create($this->import(ImportType::ALLOCATION, ImportStatus::COMPLETED, self::coverage([
            '2026-09-01' => 10,
            '2026-09-02' => 5,
            '2026-09-05' => 1,
            '2026-09-07' => 2,
        ])));

        self::assertTrue($view->isAvailable());
        self::assertTrue($view->detectsGaps);
        self::assertSame('01.09.2026', $view->firstDay);
        self::assertSame('07.09.2026', $view->lastDay);
        self::assertSame('01.09.2026 00:00', $view->firstAt);
        self::assertSame(7, $view->periodDays);
        self::assertSame(4, $view->daysWithData);
        self::assertSame(3, $view->daysWithoutData);
        self::assertTrue($view->hasGaps());
        self::assertCount(2, $view->gaps);
        self::assertSame(['03.09.2026', '04.09.2026', 2], [$view->gaps[0]->firstDay, $view->gaps[0]->lastDay, $view->gaps[0]->days]);
        self::assertSame(['06.09.2026', '06.09.2026', 1], [$view->gaps[1]->firstDay, $view->gaps[1]->lastDay, $view->gaps[1]->days]);
        self::assertSame(3, $view->gapDays());
        self::assertCount(2, $view->visibleGaps());
        self::assertSame([], $view->collapsedGaps());
    }

    #[Test]
    public function calendarPadsWholeWeeksAndUsesFourLevels(): void
    {
        // 2026-09-01 is a Tuesday, 2026-09-07 a Monday.
        $view = new ImportCoverageViewFactory()->create($this->import(ImportType::ALLOCATION, ImportStatus::COMPLETED, self::coverage([
            '2026-09-01' => 10,
            '2026-09-02' => 5,
            '2026-09-07' => 2,
        ])));

        self::assertCount(2, $view->weeks);
        $first = $view->weeks[0]->days;
        self::assertCount(7, $first);
        self::assertSame(['31.08.2026', ImportCoverageCell::OUTSIDE], [$first[0]->day, $first[0]->kind]);
        self::assertSame([ImportCoverageCell::DATA, 10, 4], [$first[1]->kind, $first[1]->count, $first[1]->level]);
        self::assertSame(2, $first[2]->level);
        self::assertSame([ImportCoverageCell::GAP, 0], [$first[3]->kind, $first[3]->level]);
        self::assertSame([ImportCoverageCell::DATA, 1], [$view->weeks[1]->days[0]->kind, $view->weeks[1]->days[0]->level]);
        self::assertSame(ImportCoverageCell::OUTSIDE, $view->weeks[1]->days[6]->kind);
        self::assertSame('2026-09-01', $view->weeks[0]->month?->format('Y-m-d'));
        self::assertNull($view->weeks[1]->month);
    }

    #[Test]
    public function monthLabelsDoNotOverlap(): void
    {
        // Starts on Sunday 2026-08-30: August only has one week of room before September.
        $view = new ImportCoverageViewFactory()->create($this->import(ImportType::ALLOCATION, ImportStatus::COMPLETED, self::coverage([
            '2026-08-30' => 1,
            '2026-10-20' => 1,
        ])));

        $labels = array_values(array_filter(array_map(
            static fn (\App\Import\UI\Http\Presenter\Coverage\ImportCoverageWeek $week): ?string => $week->month?->format('Y-m'),
            $view->weeks,
        )));
        self::assertSame(['2026-09', '2026-10'], $labels);
    }

    #[Test]
    public function closureImportsDoNotFlagDaysWithoutClosures(): void
    {
        $view = new ImportCoverageViewFactory()->create($this->import(ImportType::CLOSURE, ImportStatus::COMPLETED, self::coverage([
            '2026-09-01' => 1,
            '2026-09-05' => 3,
        ])));

        self::assertTrue($view->isAvailable());
        self::assertFalse($view->detectsGaps);
        self::assertFalse($view->hasGaps());
        self::assertSame(ImportCoverageCell::NONE, $view->weeks[0]->days[2]->kind);
    }

    #[Test]
    public function listsAllGapsAndOmitsTheCalendarForLongPeriods(): void
    {
        $days = [];
        $day = new \DateTimeImmutable('2025-01-01');
        for ($i = 0; $i < 210; ++$i) {
            $days[$day->format('Y-m-d')] = 1;
            $day = $day->modify('+2 days');
        }

        $view = new ImportCoverageViewFactory()->create($this->import(ImportType::ALLOCATION, ImportStatus::COMPLETED, self::coverage($days)));

        self::assertSame(419, $view->periodDays);
        self::assertCount(209, $view->gaps, 'All gaps are listed.');
        self::assertSame(209, $view->gapDays());
        self::assertCount(ImportCoverageView::VISIBLE_GAPS, $view->visibleGaps());
        self::assertCount(199, $view->collapsedGaps());
        self::assertSame('02.01.2025', $view->visibleGaps()[0]->firstDay);
        self::assertSame('22.01.2025', $view->collapsedGaps()[0]->firstDay, 'The eleventh gap is the first collapsed one.');
        self::assertSame([], $view->weeks);
    }

    private function import(ImportType $type, ImportStatus $status, ?ImportDataCoverage $coverage): Import
    {
        $import = new Import()->setType($type)->setStatus($status);
        $import->applyDataCoverage($coverage);

        return $import;
    }

    /**
     * @param array<string, int> $dailyCounts
     */
    private static function coverage(array $dailyCounts): ImportDataCoverage
    {
        $days = array_keys($dailyCounts);
        sort($days);

        return new ImportDataCoverage(
            array_sum($dailyCounts),
            new \DateTimeImmutable($days[0].' 00:00:00'),
            new \DateTimeImmutable($days[\count($days) - 1].' 23:00:00'),
            $dailyCounts,
        );
    }
}
