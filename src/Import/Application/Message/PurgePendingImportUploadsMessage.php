<?php

declare(strict_types=1);

namespace App\Import\Application\Message;

/**
 * Triggers the hourly deletion of background uploads of the import form that no import took over (#977).
 */
final readonly class PurgePendingImportUploadsMessage
{
}
