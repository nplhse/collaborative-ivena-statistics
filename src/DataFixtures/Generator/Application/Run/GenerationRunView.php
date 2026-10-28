<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Run;

/**
 * A generation run with its hospitals, for progress output, `app:generate:run:show` and the final summary.
 */
final readonly class GenerationRunView
{
    /**
     * @param array<string, mixed>            $settings
     * @param list<GenerationRunHospitalView> $hospitals
     */
    public function __construct(
        public string $id,
        public GenerationRunScope $scope,
        public GenerationRunTrigger $trigger,
        public int $seed,
        public ?string $fromMonth,
        public ?string $toMonth,
        public array $settings,
        public bool $notify,
        public ?int $initiatedById,
        public GenerationRunStatus $status,
        public GenerationRunStage $stage,
        public ?string $reasonCode,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $generatedAt,
        public ?\DateTimeImmutable $finishedAt,
        public array $hospitals,
    ) {
    }

    /**
     * @return array<string, int> hospital status value => number of hospitals
     */
    public function hospitalCounts(): array
    {
        $counts = [];
        foreach (GenerationRunHospitalStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }
        foreach ($this->hospitals as $hospital) {
            ++$counts[$hospital->status->value];
        }

        return $counts;
    }

    public function totalAllocations(): int
    {
        return array_sum(array_map(static fn (GenerationRunHospitalView $h): int => $h->allocations, $this->hospitals));
    }

    public function totalClosures(): int
    {
        return array_sum(array_map(static fn (GenerationRunHospitalView $h): int => $h->closures, $this->hospitals));
    }

    public function totalMonths(): int
    {
        return array_sum(array_map(static fn (GenerationRunHospitalView $h): int => $h->months, $this->hospitals));
    }

    /**
     * True when a run that is not finished has not changed for $minutes: its process or worker may be gone.
     */
    public function isStale(\DateTimeImmutable $now, int $minutes = 30): bool
    {
        return !$this->status->isTerminal() && $this->updatedAt < $now->modify(\sprintf('-%d minutes', $minutes));
    }
}
