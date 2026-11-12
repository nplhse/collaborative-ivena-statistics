<?php

declare(strict_types=1);

namespace App\Encounter\Application\Message;

/**
 * Daily scheduler job: deletes encounter upload files older than `app.encounter.source_file_retention_days`
 * (ADR 025, #1049). Imported encounters are not touched.
 */
final readonly class DeleteExpiredEncounterSourceFilesMessage
{
}
