<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Run\Message;

/**
 * Asynchronous stage of a generation run for one hospital: allocation projections of its imports, then its
 * closure analysis and closure volume (in this order, in one handler).
 */
final readonly class ProjectGeneratedHospital
{
    public function __construct(
        public string $runId,
        public int $hospitalId,
    ) {
    }
}
