<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\Allocation\Domain\Enum\ClosureCareLevel;
use App\Allocation\Domain\Enum\ClosureReason;
use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use App\DataFixtures\Pattern\Infrastructure\Sampling\WeightedCategoricalSampler;
use Random\Randomizer;

/**
 * Plans the closures of one hospital and month from their own stream (ADR 022).
 *
 * A closure event is an IVENA group (several departments with one group id), an exact-time cluster
 * (several departments with the same start and end, no group id) or a single closure, so the closure
 * analysis finds all three event types. Durations run from half an hour to at most 24 hours; autumn
 * and winter get a few more closures. The hospital profile sets the frequency, and only departments
 * the hospital offers are closed.
 */
final readonly class ClosurePlanner
{
    private const array SEASON = [1 => 1.2, 2 => 1.2, 3 => 1.1, 4 => 1.0, 5 => 0.9, 6 => 0.9, 7 => 0.9, 8 => 0.9, 9 => 1.0, 10 => 1.1, 11 => 1.2, 12 => 1.2];

    private const array EVENT_TYPES = ['group' => 0.30, 'cluster' => 0.25, 'single' => 0.45];

    /** Departments that are commonly closed, if the hospital offers them. */
    public const array DEPARTMENTS = [
        'Kardiologie' => 0.20, 'Unfallchirurgie' => 0.15, 'Allgemeinchirurgie' => 0.10, 'Gastroenterologie' => 0.10,
        'Allgemeine Neurologie' => 0.10, 'Pulmologie' => 0.10, 'Allgemeine Psychiatrie' => 0.10, 'Geburtshilfe' => 0.05,
        'Nephrologie' => 0.05, 'Infektiologie' => 0.05,
    ];

    public const array SPECIALISED_DEPARTMENTS = ['Schlaganfallmedizin' => 0.10, 'Neurochirurgie' => 0.05, 'Allgemeine Pädiatrie' => 0.05];

    private const array CARE_LEVELS = ['emergency' => 0.60, 'inpatient' => 0.30, 'outpatient' => 0.10];

    private const array REASONS = [
        'emergency_department_overload' => 0.45, 'no_bed_capacity' => 0.40, 'technical_fault' => 0.05,
        'operating_room_notice' => 0.05, 'not_specified' => 0.05,
    ];

    /** Hour of the start: more closures in the afternoon and evening. */
    private const array START_HOURS = [
        0 => 1.0, 1 => 1.0, 2 => 0.5, 3 => 0.5, 4 => 0.5, 5 => 0.5, 6 => 1.0, 7 => 1.5, 8 => 2.0, 9 => 3.0, 10 => 4.0, 11 => 5.0,
        12 => 6.0, 13 => 6.0, 14 => 6.0, 15 => 6.0, 16 => 6.0, 17 => 5.0, 18 => 4.0, 19 => 3.0, 20 => 2.5, 21 => 2.0, 22 => 1.5, 23 => 1.0,
    ];

    public function __construct(
        private RandomStreams $streams,
        private WeightedCategoricalSampler $sampler,
    ) {
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

        $random = $this->streams->stream($seed, $hospital, $month, RandomStreams::CLOSURES);
        $first = new \DateTimeImmutable($days[0]);
        $rate = $profile->closureEventsPerMonth * self::SEASON[(int) $first->format('n')] * (float) \count($days) / 30.0;
        $departments = array_filter(
            self::DEPARTMENTS + self::SPECIALISED_DEPARTMENTS,
            $profile->offers(...),
            \ARRAY_FILTER_USE_KEY,
        );
        $end = \DateTimeImmutable::createFromInterface($lastDay)->setTime(23, 59, 59);

        $closures = [];
        $events = $this->poisson($rate, $random);
        for ($event = 1; $event <= $events; ++$event) {
            $type = $this->sampler->sample(self::EVENT_TYPES, $random);
            $startsAt = new \DateTimeImmutable($days[$random->getInt(0, \count($days) - 1)])
                ->setTime((int) $this->sampler->sample(self::START_HOURS, $random), $random->getInt(0, 59));
            $endsAt = min($startsAt->modify(sprintf('+%d minutes', $this->durationMinutes($random))), $end);
            if ($endsAt <= $startsAt) {
                continue;
            }

            $count = 'single' === $type ? 1 : $random->getInt(2, 'group' === $type ? 4 : 3);
            // Unique per hospital, month and event, at most 32 characters like the column.
            $groupId = 'group' === $type ? sprintf('%s-%s-%02d', hash('crc32b', $seed.'|'.$hospital), str_replace('-', '', $month), $event) : null;
            $careLevel = ClosureCareLevel::from($this->sampler->sample(self::CARE_LEVELS, $random));
            $reason = ClosureReason::from($this->sampler->sample(self::REASONS, $random));
            foreach ($this->distinctDepartments($departments, $count, $random) as $department) {
                $closures[] = new PlannedClosure($department, $startsAt, $endsAt, $careLevel, $reason, $groupId);
            }
        }

        return $closures;
    }

    /**
     * Half an hour to two hours mostly, sometimes a shift, rarely up to a day.
     */
    private function durationMinutes(Randomizer $random): int
    {
        return match ($this->sampler->sample(['short' => 0.50, 'shift' => 0.35, 'long' => 0.15], $random)) {
            'short' => $random->getInt(30, 120),
            'shift' => $random->getInt(121, 360),
            default => $random->getInt(361, 24 * 60),
        };
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

    private function poisson(float $lambda, Randomizer $random): int
    {
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
