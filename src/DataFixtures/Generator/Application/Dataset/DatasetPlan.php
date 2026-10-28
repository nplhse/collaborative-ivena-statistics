<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Dataset;

/**
 * The checked plan of a dataset run (#877). DatasetGenerator::start() only takes a runnable plan; a plan with
 * blocked hospitals becomes runnable through withoutBlocked(), which moves them to skipped().
 */
final readonly class DatasetPlan
{
    /**
     * @param list<string>              $blockers  reasons that stop the whole run
     * @param list<DatasetHospitalPlan> $hospitals hospitals of the run, in generation order
     * @param list<DatasetHospitalPlan> $skipped   blocked hospitals left out by withoutBlocked()
     */
    public function __construct(
        public DatasetRequest $request,
        public int $seed,
        public string $patterns,
        public \DateTimeImmutable $lastDay,
        public array $blockers,
        public array $hospitals,
        public array $skipped = [],
    ) {
    }

    public function isRunnable(): bool
    {
        return [] === $this->runBlockers();
    }

    /**
     * Everything that keeps start() from accepting the plan.
     *
     * @return list<string>
     */
    public function runBlockers(): array
    {
        $blockers = $this->blockers;
        foreach ($this->hospitals as $hospital) {
            foreach ($hospital->blockers as $blocker) {
                $blockers[] = \sprintf('%s: %s', $hospital->name, $blocker);
            }
        }
        if ([] === $blockers && [] === $this->withWork()) {
            $blockers[] = 'There is nothing to generate: every month of the period already has complete generated data.';
        }

        return $blockers;
    }

    /**
     * @return list<DatasetHospitalPlan>
     */
    public function blocked(): array
    {
        return array_values(array_filter($this->hospitals, static fn (DatasetHospitalPlan $hospital): bool => !$hospital->isReady()));
    }

    /**
     * @return list<DatasetHospitalPlan>
     */
    public function withWork(): array
    {
        return array_values(array_filter($this->hospitals, static fn (DatasetHospitalPlan $hospital): bool => $hospital->hasWork()));
    }

    public function withoutBlocked(): self
    {
        return new self(
            $this->request,
            $this->seed,
            $this->patterns,
            $this->lastDay,
            $this->blockers,
            array_values(array_filter($this->hospitals, static fn (DatasetHospitalPlan $hospital): bool => $hospital->isReady())),
            [...$this->skipped, ...$this->blocked()],
        );
    }

    public function plannedAllocations(): int
    {
        return array_sum(array_map(static fn (DatasetHospitalPlan $hospital): int => $hospital->plannedAllocations, $this->withWork()));
    }
}
