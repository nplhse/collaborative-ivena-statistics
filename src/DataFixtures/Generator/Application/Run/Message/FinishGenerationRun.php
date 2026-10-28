<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Run\Message;

/**
 * Final stage of a generation run once no hospital is open: materialized views, daily KPIs, the terminal
 * status and the notifications.
 */
final readonly class FinishGenerationRun
{
    public function __construct(
        public string $runId,
    ) {
    }
}
