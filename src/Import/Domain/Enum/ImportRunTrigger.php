<?php

declare(strict_types=1);

namespace App\Import\Domain\Enum;

/**
 * Where a run came from. Decides who is told about its outcome (ADR 016):
 * an upload notifies the import's creator (and the hospital owner), a console or reprocess run only its initiator,
 * a batch run nobody.
 */
enum ImportRunTrigger: string
{
    /** A user uploaded the file in the UI. */
    case Upload = 'upload';
    /** `app:import:start` */
    case Console = 'console';
    /** One item of `app:import:requeue-all` */
    case BatchRequeue = 'batch_requeue';
    /** A user reprocessed an existing import from its detail page. */
    case Reprocess = 'reprocess';
}
