<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog;

enum ReferenceNameAliasClassification: string
{
    case Historical = 'historical';
    case FaultyCatalog = 'faulty_catalog';
    case UnambiguousAlias = 'unambiguous_alias';
    case HospitalLocal = 'hospital_local';
}
