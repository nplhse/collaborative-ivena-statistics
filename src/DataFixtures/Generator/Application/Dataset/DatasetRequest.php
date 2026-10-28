<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Dataset;

use App\DataFixtures\Generator\Application\ProfileSelection;
use App\DataFixtures\Generator\Application\Run\GenerationRunScope;
use App\DataFixtures\Generator\Application\Run\GenerationRunTrigger;

/**
 * What a dataset run is asked to generate (#877): the console command and the demo wizard (#791) build one.
 */
final readonly class DatasetRequest
{
    /**
     * @param list<int>                    $hospitalIds hospitals for the scopes One and Selected; empty for All
     * @param int|null                     $seed        null takes the configured seed
     * @param array<int, ProfileSelection> $selections  hospital id => explicit profile; without one the profile is drawn
     * @param string|null                  $patterns    pattern source; null takes FIXTURES_PATTERNS
     * @param string|null                  $retryOf     run whose failed hospitals this run generates again
     * @param \DateTimeImmutable|null      $lastDay     last day with data; null takes the end of the period or yesterday, whichever is earlier
     * @param list<int>                    $notifyUserIds users notified with notify, in addition to each hospital's owner (the demo participant of #791)
     */
    public function __construct(
        public GenerationRunScope $scope,
        public array $hospitalIds,
        public DatasetPeriod $period,
        public ?int $seed = null,
        public ExistingData $existing = ExistingData::Refuse,
        public array $selections = [],
        public ?string $patterns = null,
        public GenerationRunTrigger $trigger = GenerationRunTrigger::Console,
        public bool $notify = false,
        public ?int $initiatedById = null,
        public ?string $retryOf = null,
        public ?\DateTimeImmutable $lastDay = null,
        public array $notifyUserIds = [],
    ) {
        match ($scope) {
            GenerationRunScope::One => 1 === \count($hospitalIds) ?: throw new \InvalidArgumentException('The scope "one" takes exactly one hospital.'),
            GenerationRunScope::Selected => [] !== $hospitalIds ?: throw new \InvalidArgumentException('The scope "selected" takes at least one hospital.'),
            GenerationRunScope::AllParticipating => [] === $hospitalIds ?: throw new \InvalidArgumentException('The scope "all" takes every participating hospital; do not pass hospitals.'),
        };
        foreach ($notifyUserIds as $userId) {
            if ($userId < 1) {
                throw new \InvalidArgumentException(\sprintf('Invalid user id %d to notify.', $userId));
            }
        }
        if (\count($hospitalIds) !== \count(array_unique($hospitalIds))) {
            throw new \InvalidArgumentException('A hospital can only be selected once.');
        }
    }

    public function selectionFor(int $hospitalId): ?ProfileSelection
    {
        return $this->selections[$hospitalId] ?? null;
    }
}
