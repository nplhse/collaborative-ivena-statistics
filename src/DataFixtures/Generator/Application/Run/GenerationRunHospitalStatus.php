<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Run;

/**
 * Status of one hospital in a run: generation is synchronous (generating → generated), projections run
 * asynchronously (projecting → completed). A hospital fails in either stage.
 */
enum GenerationRunHospitalStatus: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Generated = 'generated';
    case Projecting = 'projecting';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return self::Completed === $this || self::Failed === $this;
    }
}
