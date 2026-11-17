<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\Contract;

use Cois\Plugin\EdVisit\Application\Matching\MatchRunResult;
use Cois\Plugin\EdVisit\Application\Matching\MatchScope;

/**
 * Runs the matcher of ED visits and IVENA allocations for a scope (ADR 030).
 */
interface EncounterMatcherInterface
{
    public function run(MatchScope $scope): MatchRunResult;
}
