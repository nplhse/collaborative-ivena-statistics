<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Run;

/**
 * What a generation run is asked to do, recorded so the run can be reproduced (#877).
 *
 * `settings` holds everything else that shapes the data (profile or volume overrides, pattern source, catalog);
 * it is stored as JSON and shown by `app:generate:run:show`. It must not contain real data.
 */
final readonly class GenerationRunRequest
{
    /**
     * @param list<int>                               $hospitalIds hospitals of the run, in generation order
     * @param string|null                             $fromMonth   first month as Y-m
     * @param string|null                             $toMonth     last month as Y-m
     * @param array<string, scalar|array<mixed>|null> $settings
     * @param bool                                    $notify      notify the owner of each completed hospital in-app
     */
    public function __construct(
        public GenerationRunScope $scope,
        public array $hospitalIds,
        public int $seed,
        public ?string $fromMonth = null,
        public ?string $toMonth = null,
        public array $settings = [],
        public GenerationRunTrigger $trigger = GenerationRunTrigger::Console,
        public bool $notify = false,
        public ?int $initiatedById = null,
    ) {
        if ([] === $hospitalIds) {
            throw new \InvalidArgumentException('A generation run needs at least one hospital.');
        }
        if (GenerationRunScope::One === $scope && 1 !== \count($hospitalIds)) {
            throw new \InvalidArgumentException('The scope "one" takes exactly one hospital.');
        }
        if (\count($hospitalIds) !== \count(array_unique($hospitalIds))) {
            throw new \InvalidArgumentException('A hospital can only be part of a run once.');
        }
    }
}
