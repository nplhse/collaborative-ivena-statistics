<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Application\Matching\MatchRunResult;
use App\Encounter\Application\Matching\MatchScope;

/**
 * Runs the matcher of ED visits and IVENA allocations for a scope (ADR 029).
 */
interface EncounterMatcherInterface
{
    public function run(MatchScope $scope): MatchRunResult;
}
