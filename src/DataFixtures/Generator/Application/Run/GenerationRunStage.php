<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Run;

/**
 * Where a run stands: synchronous generation, asynchronous projections per hospital, the shared final step
 * (materialized views, KPIs), done.
 */
enum GenerationRunStage: string
{
    case Generating = 'generating';
    case Projecting = 'projecting';
    case Finishing = 'finishing';
    case Done = 'done';
}
