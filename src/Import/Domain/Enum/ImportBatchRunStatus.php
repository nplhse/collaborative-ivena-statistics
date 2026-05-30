<?php

declare(strict_types=1);

namespace App\Import\Domain\Enum;

enum ImportBatchRunStatus: string
{
    case Running = 'running';
    case Finished = 'finished';
    case Interrupted = 'interrupted';
    case Failed = 'failed';
}
