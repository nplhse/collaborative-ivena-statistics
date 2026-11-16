<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Assignment;

/**
 * Rolls back the savepoint of one retried record whose handler attached nothing (record gone or already
 * attached meanwhile); AssignmentRetrier counts it as skipped.
 *
 * @internal
 */
final class RecordChangedMeanwhile extends \RuntimeException
{
}
