<?php

declare(strict_types=1);

namespace App\Kpi\Application\Service;

use App\Import\Domain\Entity\Import;
use App\Import\Domain\Service\ImportEvaluation;

final class ImportFailureReasonResolver
{
    public function resolve(Import $import): string
    {
        $total = $import->getRowCount() ?? 0;
        if ($total <= 0) {
            return 'kpi.failure_reason.no_rows';
        }

        $rejected = $import->getRowsRejected() ?? 0;
        if (($rejected / $total) >= ImportEvaluation::FAILED_MIN_RATIO) {
            return 'kpi.failure_reason.high_rejection';
        }

        return 'kpi.failure_reason.generic';
    }
}
