<?php

declare(strict_types=1);

namespace App\Content\Application\Event;

use App\Content\Application\Media\MediaIntegrityReport;

/**
 * A run of the media integrity check was stored (ADR 039). Not dispatched for dry runs.
 */
final readonly class MediaIntegrityChecked
{
    public function __construct(
        public MediaIntegrityReport $report,
    ) {
    }
}
