<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Run;

/**
 * Announces the outcome of a finished run to the participants of its completed hospitals.
 */
interface GenerationRunNotifierInterface
{
    /**
     * @return int notifications requested (deduplication may turn repeats into no-ops)
     */
    public function notify(GenerationRunView $run): int;
}
