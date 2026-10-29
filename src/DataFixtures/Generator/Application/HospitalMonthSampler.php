<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\FixtureVolume;
use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use App\DataFixtures\Pattern\Application\InvalidPatternPackageException;
use App\DataFixtures\Pattern\Application\PatternLookupResolver;
use App\DataFixtures\Pattern\Application\PatternSampler;
use App\DataFixtures\Pattern\Application\PatternSetLoader;

/**
 * Samples the closures and allocations of one hospital and month without writing anything.
 *
 * `HospitalMonthGenerator` writes the result into the database; the demo CSV export (#791) writes the
 * same rows into IVENA files. Both draw from the same random streams (ADR 022), so a month gets the
 * same rows either way.
 */
final readonly class HospitalMonthSampler
{
    public function __construct(
        private PatternSetLoader $patterns,
        private HospitalProfileResolver $profiles,
        private PatternSampler $patternSampler,
        private RandomStreams $streams,
        private ClosurePlanner $closurePlanner,
        private PatternLookupResolver $lookups,
    ) {
    }

    /**
     * The hospital's profile; the closure share of the preset applies unless the selection sets one.
     * Every path that samples a month (fixture load, app:generate:imports, demo CSV) resolves it here,
     * so they get the same rows.
     *
     * @throws \DomainException when the selection is not usable
     */
    public function profile(FixtureVolume $volume, Hospital $hospital, ?ProfileSelection $selection = null): HospitalProfile
    {
        return $this->profiles->resolve($hospital, $volume->seed, ($selection ?? new ProfileSelection())->withDefaultClosureShare($volume->closureShare));
    }

    /**
     * @return list<PlannedClosure>
     */
    public function closures(FixtureVolume $volume, Hospital $hospital, ImportBatch $batch, \DateTimeImmutable $lastDay, ?ProfileSelection $selection = null): array
    {
        $profile = $this->profile($volume, $hospital, $selection);

        return $this->closurePlanner->plan($volume->seed, $hospital->getPublicIdString(), $batch->month, $profile, array_keys($batch->dailyCounts), $lastDay);
    }

    /**
     * The allocations of the month in order of day. A row is flagged `departmentWasClosed` when its department
     * has one of the given closures at the time the allocation was created.
     *
     * @param list<PlannedClosure> $closures
     *
     * @return iterable<SampledAllocation>
     *
     * @throws InvalidPatternPackageException when the pattern package of the volume is invalid
     */
    public function allocations(FixtureVolume $volume, Hospital $hospital, ImportBatch $batch, array $closures, ?ProfileSelection $selection = null): iterable
    {
        $profile = $this->profile($volume, $hospital, $selection);
        $closed = ClosedDepartments::of($closures, $this->lookups);
        $pattern = $profile->applyTo($this->patterns->load($volume->patterns)->get($profile->segment));
        $random = $this->streams->stream($volume->seed, $hospital->getPublicIdString(), $batch->month, RandomStreams::ALLOCATIONS);

        foreach ($batch->dailyCounts as $day => $count) {
            $dayStart = new \DateTimeImmutable($day.' 00:00:00');
            $dayEnd = new \DateTimeImmutable($day.' 23:59:59');
            for ($i = 0; $i < $count; ++$i) {
                $sampled = $this->patternSampler->sample($pattern, $dayStart, $dayEnd, $random);

                yield new SampledAllocation($sampled, $closed->isClosed((int) $sampled->department->getId(), $sampled->createdAt));
            }
        }
    }
}
