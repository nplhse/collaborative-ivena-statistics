<?php

declare(strict_types=1);

namespace App\Import\Domain\Enum;

enum ImportBatchRunItemStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Queued = 'queued';
    case DispatchFailed = 'dispatch_failed';
    case Interrupted = 'interrupted';
    case Skipped = 'skipped';
}
