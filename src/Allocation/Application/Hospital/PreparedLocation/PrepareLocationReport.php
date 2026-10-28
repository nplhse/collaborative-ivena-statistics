<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\PreparedLocation;

/** @psalm-immutable */
final readonly class PrepareLocationReport
{
    /**
     * @param list<array{0: string, 1: string, 2: string}> $steps rows of [step, detail, status]
     */
    public function __construct(
        public bool $success,
        public bool $dryRun,
        public ?string $error = null,
        public array $steps = [],
        public int $requestsRequired = 0,
        public int $requestsExecuted = 0,
        public bool $rateLimited = false,
        public ?PreparedLocation $location = null,
    ) {
    }
}
