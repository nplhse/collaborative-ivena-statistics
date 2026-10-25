<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeAnchor;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeAnchorBuilder;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeClock;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeMetricKind;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeRelevance;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeSegments;
use PHPUnit\Framework\TestCase;

final class ClosureVolumeMetricAnchorTest extends TestCase
{
    public function testEveryPopulationGetsAllFiveMetrics(): void
    {
        $anchors = new ClosureVolumeAnchorBuilder()->metricAnchors(
            [$this->row(1, 7, 11, '10:00', '14:00', 'emergency')],
            40,
            [7 => [11]],
        );

        self::assertCount(15, $anchors);
        $emergency = $this->find($anchors, 'department', ClosureVolumeMetricKind::Emergency);
        self::assertSame(ClosureVolumeRelevance::Closed, $emergency->relevance);
        self::assertSame(1, $emergency->closedDepartmentCount);
        self::assertSame([['10:00', '14:00']], $this->hours($emergency));

        $inpatient = $this->find($anchors, 'department', ClosureVolumeMetricKind::Inpatient);
        self::assertSame(ClosureVolumeRelevance::None, $inpatient->relevance);
        self::assertSame(0, $inpatient->closedDepartmentCount);
        self::assertSame([['10:00', '14:00']], $this->hours($inpatient), 'Comparison rows use the population extent.');

        self::assertSame(ClosureVolumeRelevance::None, $this->find($anchors, 'department', ClosureVolumeMetricKind::Resus)->relevance);
        self::assertSame(ClosureVolumeRelevance::Closed, $this->find($anchors, 'speciality', ClosureVolumeMetricKind::Emergency)->relevance);
        self::assertSame(ClosureVolumeRelevance::Closed, $this->find($anchors, 'hospital', ClosureVolumeMetricKind::Emergency)->relevance);
    }

    public function testUrgencyMetricsKeepTheirOwnSegments(): void
    {
        $anchors = new ClosureVolumeAnchorBuilder()->metricAnchors([
            $this->row(1, 7, 11, '10:00', '12:00', 'emergency'),
            $this->row(1, 7, 11, '11:00', '16:00', 'inpatient'),
        ], 40, []);

        self::assertSame([['10:00', '12:00']], $this->hours($this->find($anchors, 'department', ClosureVolumeMetricKind::Emergency)));
        self::assertSame([['11:00', '16:00']], $this->hours($this->find($anchors, 'department', ClosureVolumeMetricKind::Inpatient)));
        self::assertSame([['10:00', '16:00']], $this->hours($this->find($anchors, 'department', ClosureVolumeMetricKind::Outpatient)));
    }

    public function testResourceMetricsNeedAllUrgenciesClosedAtTheSameTime(): void
    {
        $anchors = new ClosureVolumeAnchorBuilder()->metricAnchors([
            $this->row(1, 7, 11, '10:00', '14:00', 'emergency'),
            $this->row(1, 7, 11, '12:00', '16:00', 'inpatient'),
            $this->row(1, 7, 11, '11:00', '13:00', 'outpatient'),
            $this->row(1, 7, 11, '15:00', '17:00', 'other'),
        ], 40, []);

        foreach ([ClosureVolumeMetricKind::Resus, ClosureVolumeMetricKind::Cathlab] as $metric) {
            $anchor = $this->find($anchors, 'department', $metric);
            self::assertSame(ClosureVolumeRelevance::Closed, $anchor->relevance);
            self::assertSame([['12:00', '13:00'], ['15:00', '17:00']], $this->hours($anchor));
        }
    }

    public function testPartlyOpenDepartmentDoesNotBindResourceMetrics(): void
    {
        $anchors = new ClosureVolumeAnchorBuilder()->metricAnchors([
            $this->row(1, 7, 11, '10:00', '14:00', 'emergency'),
            $this->row(1, 7, 11, '10:00', '14:00', 'inpatient'),
        ], 40, []);

        self::assertSame(ClosureVolumeRelevance::None, $this->find($anchors, 'department', ClosureVolumeMetricKind::Resus)->relevance);
        self::assertSame(ClosureVolumeRelevance::None, $this->find($anchors, 'hospital', ClosureVolumeMetricKind::Cathlab)->relevance);
    }

    public function testAggregatedPopulationsAreClosedOnlyWhenEveryDepartmentIs(): void
    {
        $builder = new ClosureVolumeAnchorBuilder();
        $rows = [
            $this->row(1, 7, 11, '10:00', '14:00', 'emergency'),
            $this->row(1, 7, 12, '12:00', '18:00', 'other'),
        ];

        $anchors = $builder->metricAnchors($rows, 40, [7 => [11, 12, 13], 8 => [21]]);

        $speciality = $this->find($anchors, 'speciality', ClosureVolumeMetricKind::Emergency);
        self::assertSame(ClosureVolumeRelevance::Partial, $speciality->relevance);
        self::assertSame(2, $speciality->closedDepartmentCount);
        self::assertSame([['10:00', '18:00']], $this->hours($speciality));
        $inpatient = $this->find($anchors, 'speciality', ClosureVolumeMetricKind::Inpatient);
        self::assertSame(1, $inpatient->closedDepartmentCount);
        self::assertSame([['12:00', '18:00']], $this->hours($inpatient));
        $hospital = $this->find($anchors, 'hospital', ClosureVolumeMetricKind::Emergency);
        self::assertSame(ClosureVolumeRelevance::Partial, $hospital->relevance);
        self::assertSame(2, $hospital->closedDepartmentCount);

        $closed = $builder->metricAnchors($rows, 40, [7 => [11, 12]]);
        self::assertSame(ClosureVolumeRelevance::Closed, $this->find($closed, 'speciality', ClosureVolumeMetricKind::Emergency)->relevance);
        self::assertSame(ClosureVolumeRelevance::Partial, $this->find($closed, 'speciality', ClosureVolumeMetricKind::Inpatient)->relevance);
        self::assertSame(ClosureVolumeRelevance::Closed, $this->find($closed, 'hospital', ClosureVolumeMetricKind::Emergency)->relevance);
    }

    public function testClosedDepartmentsAreAlwaysPartOfTheUniverse(): void
    {
        $anchors = new ClosureVolumeAnchorBuilder()->metricAnchors(
            [$this->row(1, 7, 11, '10:00', '14:00', 'other')],
            40,
            [],
        );

        self::assertSame(ClosureVolumeRelevance::Closed, $this->find($anchors, 'speciality', ClosureVolumeMetricKind::Resus)->relevance);
        self::assertSame(ClosureVolumeRelevance::Closed, $this->find($anchors, 'hospital', ClosureVolumeMetricKind::Outpatient)->relevance);
    }

    public function testSegmentUnionMergesTouchingAndIntersectionNeedsOverlap(): void
    {
        $union = ClosureVolumeSegments::union([
            [$this->at('12:00'), $this->at('13:00')],
            [$this->at('10:00'), $this->at('12:00')],
            [$this->at('15:00'), $this->at('15:00')],
        ]);
        self::assertSame([['10:00', '13:00']], $this->format($union));

        self::assertSame([], ClosureVolumeSegments::intersect([
            [[$this->at('10:00'), $this->at('11:00')]],
            [[$this->at('11:00'), $this->at('12:00')]],
        ]));
        self::assertSame([], ClosureVolumeSegments::intersect([]));
    }

    /**
     * @return array<string, int|string>
     */
    private function row(int $eventId, int $specialityId, int $departmentId, string $start, string $end, string $careLevel): array
    {
        return [
            'event_id' => $eventId,
            'speciality_id' => $specialityId,
            'department_id' => $departmentId,
            'starts_at' => '2026-05-12 '.$start.':00',
            'ends_at' => '2026-05-12 '.$end.':00',
            'care_level' => $careLevel,
        ];
    }

    /**
     * @param list<ClosureVolumeAnchor> $anchors
     */
    private function find(array $anchors, string $scope, ClosureVolumeMetricKind $metric): ClosureVolumeAnchor
    {
        foreach ($anchors as $anchor) {
            if ($anchor->scope === $scope && $anchor->metric === $metric && ('department' !== $scope || 11 === $anchor->departmentId)) {
                return $anchor;
            }
        }
        self::fail(sprintf('No %s anchor for %s.', $scope, $metric->value));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function hours(ClosureVolumeAnchor $anchor): array
    {
        return $this->format($anchor->segments);
    }

    /**
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $segments
     *
     * @return list<array{0: string, 1: string}>
     */
    private function format(array $segments): array
    {
        return array_map(static fn (array $segment): array => [$segment[0]->format('H:i'), $segment[1]->format('H:i')], $segments);
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-05-12 '.$time.':00', ClosureVolumeClock::zone());
    }
}
