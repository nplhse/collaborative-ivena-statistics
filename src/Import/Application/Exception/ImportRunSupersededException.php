<?php

declare(strict_types=1);

namespace App\Import\Application\Exception;

/**
 * Thrown before a commit when a newer run has replaced the run being processed. The commit is rolled back.
 */
final class ImportRunSupersededException extends \RuntimeException
{
    public function __construct(int $importId, string $runId)
    {
        parent::__construct(\sprintf('Run %s is no longer the current run of import #%d.', $runId, $importId));
    }
}
