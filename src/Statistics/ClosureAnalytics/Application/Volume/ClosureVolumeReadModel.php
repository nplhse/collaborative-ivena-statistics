<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureVolumeProjectionQuery;

final readonly class ClosureVolumeReadModel
{
    public function __construct(
        private ClosureVolumeProjectionQuery $query,
        private ClosureVolumeBurdenAggregator $burdenAggregator,
        private ClosureVolumeHospitalBaseline $baseline,
        private ClosureVolumeWindowAggregator $windows,
        private ClosureVolumeChartFactory $charts,
        private ClosureVolumeReferenceConfig $config,
    ) {
    }

    public function burden(ClosureAnalyticsCriteria $criteria, ClosureVolumeStratum $stratum): ClosureVolumeBurdenView
    {
        $hospitalIds = $this->hospitalIds($criteria);
        if ([] === $hospitalIds || !$this->query->hasRows($hospitalIds)) {
            return ClosureVolumeBurdenView::unavailable($stratum, $this->config->referenceWeeks, $this->config->minimumReferenceSlots);
        }

        $now = ClosureVolumeClock::now();
        $periodTo = $criteria->period->toExclusive;
        if (!$periodTo instanceof \DateTimeImmutable || $periodTo > $now) {
            $periodTo = $now;
        }
        $slices = [];
        foreach ($this->query->affectedRows($criteria, $stratum) as $row) {
            $start = new \DateTimeImmutable((string) $row['bucket_start']);
            $end = new \DateTimeImmutable((string) $row['bucket_end']);
            $overlap = ClosureVolumeClock::overlapSeconds($start, $end, $criteria->period->from, $periodTo);
            $slices[] = new ClosureVolumeBurdenSlice(
                (int) $row['hospital_id'],
                (int) $row['department_id'],
                (int) $row['urgency_code'],
                (string) $row['stratum'],
                ClosureVolumeClock::wall($start)->format('Y-m-d H:i:s'),
                $this->nullableFloat($row['observed_area']),
                $this->nullableFloat($row['expected_area']),
                (int) $row['bucket_seconds'],
                $overlap,
                ClosureVolumeClock::wall(new \DateTimeImmutable((string) $row['reference_cutoff'])),
                (int) $row['speciality_id'],
            );
        }
        $totals = $this->burdenAggregator->aggregate($slices);
        $hospital = $this->hospitalExpected($hospitalIds, $criteria->period->from, $periodTo, $stratum);

        return new ClosureVolumeBurdenView(
            $stratum,
            true,
            $totals->observedArea,
            $totals->expectedArea,
            $totals->absoluteDeviation,
            $totals->relativeDeviation,
            $hospital['expected'],
            ClosureVolumeDeviation::share($totals->expectedArea, $hospital['expected']),
            $totals->partialReference || $hospital['partial'],
            $totals->partialObservation,
            $this->config->referenceWeeks,
            $this->config->minimumReferenceSlots,
        );
    }

    public function detail(
        ClosureAnalyticsCriteria $criteria,
        int $eventId,
        int $hospitalId,
        ClosureVolumeStratum $stratum,
        ClosureVolumeSeriesScope $seriesScope = ClosureVolumeSeriesScope::Department,
    ): ClosureVolumeDetailView {
        $hospitalIds = $this->hospitalIds($criteria);
        $bounds = $this->query->eventMembers($eventId, $hospitalIds);
        $built = $this->query->hasRows($hospitalIds);
        $applicable = false;
        $wholeDepartment = false;
        $startsAt = null;
        $endsAt = null;
        $departmentIds = [];
        $affected = [];
        foreach ($bounds as $bound) {
            $applicable = $applicable || ClosureVolumePopulation::covers($bound['careLevel'], $stratum);
            $wholeDepartment = $wholeDepartment || 'other' === $bound['careLevel'];
            $departmentIds[] = $bound['departmentId'];
            $affected[$bound['departmentName']][$bound['careLevel']] = $bound['careLevel'];
            $startsAt = null === $startsAt || $bound['startsAt'] < $startsAt ? $bound['startsAt'] : $startsAt;
            $endsAt = null === $endsAt || $bound['endsAt'] > $endsAt ? $bound['endsAt'] : $endsAt;
        }
        $affectedDepartments = [];
        foreach ($affected as $name => $careLevels) {
            $affectedDepartments[] = ['name' => $name, 'careLevels' => array_values($careLevels)];
        }
        if (!$built || !$startsAt instanceof \DateTimeImmutable || !$endsAt instanceof \DateTimeImmutable) {
            return new ClosureVolumeDetailView(
                $stratum,
                $built,
                false,
                false,
                false,
                $wholeDepartment,
                null,
                [],
                $this->emptyChart(),
                [],
                null,
                $this->config->referenceWeeks,
                $this->config->minimumReferenceSlots,
                $affectedDepartments,
            );
        }

        $now = ClosureVolumeClock::now();
        $drafts = $applicable
            ? $this->dedupe($this->query->draftsForEvent($eventId, $hospitalIds, $stratum, $seriesScope))
            : [];
        $windowRows = $this->windows->aggregate($drafts, $startsAt, $endsAt, $now, $applicable);
        $during = null;
        foreach ($windowRows as $window) {
            if ('during' === $window->kind) {
                $during = $window;
            }
        }
        $comparison = $this->windows->comparison($windowRows);
        $contextStart = ClosureVolumeClock::wall($startsAt)->modify('-'.ClosureVolumeReferenceConfig::CONTEXT_HOURS.' hours');
        $contextEnd = ClosureVolumeClock::wall($endsAt)->modify('+'.ClosureVolumeReferenceConfig::CONTEXT_HOURS.' hours');

        $chart = $this->charts->build($drafts);
        $chart['barSeries'] = $this->charts->buildBarSeries(
            $this->dedupe($this->query->draftsForEventBarLayers($eventId, $hospitalIds, $seriesScope)),
        );
        if (ClosureVolumeSeriesScope::Department === $seriesScope && $applicable) {
            $referenceChart = $this->charts->build($this->dedupe($this->query->draftsForEvent(
                $eventId,
                $hospitalIds,
                $stratum,
                ClosureVolumeSeriesScope::Speciality,
            )));
            $chart['referenceAreaRatio'] = $referenceChart['areaRatio'];
        }

        return new ClosureVolumeDetailView(
            $stratum,
            true,
            $applicable,
            $this->extendsBeyond($criteria, $contextStart, $contextEnd),
            ClosureVolumeClock::wall($endsAt) > $now,
            $wholeDepartment,
            $during,
            $windowRows,
            $chart,
            $this->query->otherClosures($hospitalId, $hospitalIds, $departmentIds, $eventId, $contextStart, $contextEnd),
            null === $comparison ? null : ['pre' => $comparison[0]->kind, 'post' => $comparison[1]->kind],
            $this->config->referenceWeeks,
            $this->config->minimumReferenceSlots,
            $affectedDepartments,
        );
    }

    /**
     * @return list<int>
     */
    private function hospitalIds(ClosureAnalyticsCriteria $criteria): array
    {
        return $criteria->scope->hospitalIds ?? [];
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array{expected: ?float, partial: bool}
     */
    private function hospitalExpected(array $hospitalIds, ?\DateTimeImmutable $from, \DateTimeImmutable $to, ClosureVolumeStratum $stratum): array
    {
        $to = ClosureVolumeClock::wall($to);
        if (!$from instanceof \DateTimeImmutable) {
            $from = $this->query->earliestAllocation($to, $hospitalIds);
        }
        if (!$from instanceof \DateTimeImmutable) {
            return ['expected' => null, 'partial' => false];
        }
        $from = ClosureVolumeClock::wall($from);
        $refFrom = $from->modify(sprintf('-%d weeks', $this->config->referenceWeeks));
        $byHospital = [];
        foreach ($this->query->hospitalHours($hospitalIds, $refFrom, $to) as $row) {
            $hospitalId = (int) $row['hospital_id'];
            $hour = (string) $row['hour_start'];
            $byHospital[$hospitalId]['covered'][substr($hour, 0, 10)] = true;
            $byHospital[$hospitalId]['counts'][$row['urgency_code'].'|base|'.$hour] = (int) $row['base_count'];
            $byHospital[$hospitalId]['counts'][$row['urgency_code'].'|resus|'.$hour] = (int) $row['resus_count'];
            $byHospital[$hospitalId]['counts'][$row['urgency_code'].'|cathlab|'.$hour] = (int) $row['cathlab_count'];
        }

        $expected = 0.0;
        $known = false;
        $partial = false;
        foreach ($hospitalIds as $hospitalId) {
            $measured = $this->baseline->measure(
                $from,
                $to,
                $byHospital[$hospitalId]['counts'] ?? [],
                $byHospital[$hospitalId]['covered'] ?? [],
                $stratum,
            );
            if (null !== $measured['expected']) {
                $expected += $measured['expected'];
                $known = true;
            }
            $partial = $partial || $measured['partial'];
        }

        return ['expected' => $known ? $expected : null, 'partial' => $partial];
    }

    /**
     * @param list<ClosureVolumeHourDraft> $drafts
     *
     * @return list<ClosureVolumeHourDraft>
     */
    private function dedupe(array $drafts): array
    {
        /** @var array<string, ClosureVolumeHourDraft> $unique */
        $unique = [];
        foreach ($drafts as $draft) {
            $key = $draft->scope.'|'.$draft->specialityId.'|'.$draft->departmentId.'|'.$draft->urgencyCode.'|'.$draft->stratum.'|'.$draft->bucketStart->format('Y-m-d H:i:s');
            $existing = $unique[$key] ?? null;
            if (!$existing instanceof ClosureVolumeHourDraft || $draft->referenceCutoff < $existing->referenceCutoff) {
                $unique[$key] = $draft;
            }
        }

        return array_values($unique);
    }

    private function extendsBeyond(ClosureAnalyticsCriteria $criteria, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        if ($criteria->period->from instanceof \DateTimeImmutable && $start < ClosureVolumeClock::wall($criteria->period->from)) {
            return true;
        }

        return $criteria->period->toExclusive instanceof \DateTimeImmutable
            && $end > ClosureVolumeClock::wall($criteria->period->toExclusive);
    }

    private function nullableFloat(int|string|null $value): ?float
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (float) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyChart(): array
    {
        return [
            'categories' => [],
            'starts' => [],
            'observed' => [],
            'expected' => [],
            'areaRatio' => [],
            'hospitalRatio' => [],
            'influenced' => [],
            'closureBands' => [],
            'referenceAreaRatio' => null,
        ];
    }
}
