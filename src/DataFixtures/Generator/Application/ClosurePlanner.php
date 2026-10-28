<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\Allocation\Domain\Enum\ClosureCareLevel;
use App\Allocation\Domain\Enum\ClosureReason;
use App\DataFixtures\Generator\Application\Catalog\GeneratorKeys;
use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use App\DataFixtures\Pattern\Infrastructure\Sampling\WeightedCategoricalSampler;
use App\DataFixtures\Structure\Application\DefaultStructurePatterns;
use App\DataFixtures\Structure\Application\DepartmentStructure;
use App\DataFixtures\Structure\Dto\ClosureModel;
use Random\Randomizer;

/**
 * Plans the closures of one hospital and month from their own stream (ADR 022).
 *
 * The hospital profile sets how much is closed: the share of department-hours of its offered
 * departments. The closure model sets how: how many departments one event closes, how long, with
 * which care level and on which weekday. The number of events follows from both, so a share of
 * 10 % means about 10 % of the department-hours are closed (merged per department), whatever the
 * events look like:
 *
 *   events = −ln(1 − share) × departments × hours ÷ (expected departments × expected hours of an event)
 *
 * The logarithm makes up for events that overlap on one department. Autumn and winter get somewhat
 * more and summer fewer, with the yearly mean unchanged.
 *
 * An event with several departments is an IVENA group (one group id) or an exact-time cluster (same
 * start and end, no group id), a one-department event a single closure, so the closure analysis
 * finds all three event types. A closure lasts at most 24 hours; a whole day starts at midnight.
 * Only departments the hospital offers are closed.
 */
final readonly class ClosurePlanner
{
    private const array SEASON = [1 => 1.2, 2 => 1.2, 3 => 1.1, 4 => 1.0, 5 => 0.9, 6 => 0.9, 7 => 0.9, 8 => 0.9, 9 => 1.0, 10 => 1.1, 11 => 1.2, 12 => 1.2];

    private const array REASONS = [
        'emergency_department_overload' => 0.45, 'no_bed_capacity' => 0.40, 'technical_fault' => 0.05,
        'operating_room_notice' => 0.05, 'not_specified' => 0.05,
    ];

    /** Hour of the start: more closures in the afternoon and evening. */
    private const array START_HOURS = [
        0 => 1.0, 1 => 1.0, 2 => 0.5, 3 => 0.5, 4 => 0.5, 5 => 0.5, 6 => 1.0, 7 => 1.5, 8 => 2.0, 9 => 3.0, 10 => 4.0, 11 => 5.0,
        12 => 6.0, 13 => 6.0, 14 => 6.0, 15 => 6.0, 16 => 6.0, 17 => 5.0, 18 => 4.0, 19 => 3.0, 20 => 2.5, 21 => 2.0, 22 => 1.5, 23 => 1.0,
    ];

    private const int DAY_MINUTES = 1440;

    public function __construct(
        private RandomStreams $streams,
        private WeightedCategoricalSampler $sampler,
        private DefaultStructurePatterns $structures,
    ) {
    }

    /**
     * Expected closure events in a period, before the random count.
     *
     * @param int   $departments offered departments
     * @param float $hours       hours of the period
     */
    public static function expectedEvents(ClosureModel $model, float $share, int $departments, float $hours): float
    {
        if ($share <= 0.0 || $departments <= 0 || $hours <= 0.0) {
            return 0.0;
        }

        return -log(1.0 - $share) * (float) $departments * $hours / ($model->expectedDepartments($departments) * $model->expectedHours());
    }

    /**
     * @param list<string> $days the days of the month (Y-m-d) that are generated
     *
     * @return list<PlannedClosure>
     */
    public function plan(int $seed, string $hospital, string $month, HospitalProfile $profile, array $days, \DateTimeImmutable $lastDay): array
    {
        if ([] === $days) {
            return [];
        }

        $model = $this->structures->get()->closureModel();
        $departments = $this->offeredDepartments($profile, $model);
        $random = $this->streams->stream($seed, $hospital, $month, RandomStreams::CLOSURES);
        $season = self::SEASON[(int) new \DateTimeImmutable($days[0])->format('n')] * 12.0 / array_sum(self::SEASON);
        $rate = self::expectedEvents($model, $profile->closureShare, \count($departments), 24.0 * (float) \count($days)) * $season;
        $dayWeights = $this->dayWeights($days, $model);
        $end = \DateTimeImmutable::createFromInterface($lastDay)->setTime(23, 59, 59);

        $closures = [];
        $events = $this->poisson($rate, $random);
        for ($event = 1; $event <= $events; ++$event) {
            [$low, $high] = $model->departmentRange($this->sampler->sample(array_map(static fn (array $size): float => $size['share'], $model->eventSizes), $random), \count($departments));
            $count = $random->getInt($low, $high);
            $type = 1 === $count ? 'single' : ($random->nextFloat() < $model->groupShare ? 'group' : 'cluster');

            $day = new \DateTimeImmutable($this->sampler->sample($dayWeights, $random));
            $duration = $model->durations[$this->sampler->sample(array_map(static fn (array $duration): float => $duration['share'], $model->durations), $random)];
            $minutes = $random->getInt($duration['min'], $duration['max']);
            $startsAt = self::DAY_MINUTES === $minutes
                ? $day->setTime(0, 0)
                : $day->setTime((int) $this->sampler->sample(self::START_HOURS, $random), $random->getInt(0, 59));
            $endsAt = min($startsAt->modify(sprintf('+%d minutes', $minutes)), $end);

            // Unique per hospital, month and event, at most 32 characters like the column.
            $groupId = 'group' === $type ? sprintf('%s-%s-%02d', hash('crc32b', $seed.'|'.$hospital), str_replace('-', '', $month), $event) : null;
            $careLevel = ClosureCareLevel::from($this->sampler->sample($model->careLevels, $random));
            $reason = ClosureReason::from($this->sampler->sample(self::REASONS, $random));
            $picked = $this->distinctDepartments($departments, $count, $random);
            if ($endsAt <= $startsAt) {
                continue;
            }
            foreach ($picked as $department) {
                $closures[] = new PlannedClosure($department, $startsAt, $endsAt, $careLevel, $reason, $groupId);
            }
        }

        return $closures;
    }

    /**
     * The departments the hospital offers (default labels), weighted by their field.
     *
     * @return array<string, float>
     */
    private function offeredDepartments(HospitalProfile $profile, ClosureModel $model): array
    {
        $departments = [];
        foreach (DepartmentStructure::all() as $key) {
            $label = GeneratorKeys::DEPARTMENTS[$key]['label'];
            if ($profile->offers($label)) {
                $departments[$label] = $model->fieldWeights[GeneratorKeys::DEPARTMENTS[$key]['speciality']] ?? 1.0;
            }
        }

        return $departments;
    }

    /**
     * @param list<string> $days
     *
     * @return array<string, float> day → weight by its weekday
     */
    private function dayWeights(array $days, ClosureModel $model): array
    {
        $weights = [];
        foreach ($days as $day) {
            $weights[$day] = $model->weekdays[(int) new \DateTimeImmutable($day)->format('N')] ?? 1.0;
        }

        return $weights;
    }

    /**
     * @param array<string, float> $departments
     *
     * @return list<string>
     */
    private function distinctDepartments(array $departments, int $count, Randomizer $random): array
    {
        $picked = [];
        while (\count($picked) < $count && \count($picked) < \count($departments)) {
            $department = $this->sampler->sample(array_diff_key($departments, array_flip($picked)), $random);
            $picked[] = $department;
        }

        return $picked;
    }

    /**
     * Knuth's method for small rates, a normal approximation for large ones (Knuth underflows above
     * about 700 and gets slow long before).
     */
    private function poisson(float $lambda, Randomizer $random): int
    {
        if ($lambda <= 0.0) {
            return 0;
        }
        if ($lambda > 30.0) {
            $u1 = max(1e-12, $random->nextFloat());
            $u2 = $random->nextFloat();

            return max(0, (int) round($lambda + sqrt(-2.0 * log($u1)) * cos(2.0 * \M_PI * $u2) * sqrt($lambda)));
        }

        $limit = exp(-$lambda);
        $count = 0;
        $product = $random->nextFloat();
        while ($product > $limit) {
            ++$count;
            $product *= $random->nextFloat();
        }

        return $count;
    }
}
