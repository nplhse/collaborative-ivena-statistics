<?php

declare(strict_types=1);

namespace App\Import\Application\Audit;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\MciCase;
use App\Import\Domain\Entity\ImportReject;

final class ImportRunSuppressedAuditClasses
{
    /** @return list<class-string> */
    public static function fqcnList(): array
    {
        return [
            Allocation::class,
            IndicationRaw::class,
            ImportReject::class,
            MciCase::class,
        ];
    }
}
