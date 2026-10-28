<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Run;

/**
 * Status of a whole run. `failed` also covers partial runs: at least one hospital failed.
 */
enum GenerationRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return self::Completed === $this || self::Failed === $this;
    }
}
