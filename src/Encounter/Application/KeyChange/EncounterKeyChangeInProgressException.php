<?php

declare(strict_types=1);

namespace App\Encounter\Application\KeyChange;

/**
 * A key change of the hospital is still in progress (the deletion of the old data has not finished);
 * another one cannot start yet.
 */
final class EncounterKeyChangeInProgressException extends \RuntimeException
{
    public function __construct(int $hospitalId)
    {
        parent::__construct(sprintf('A key change of hospital #%d is already in progress.', $hospitalId));
    }
}
