<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * Administrative `case_status` of the `ed_visit` exchange format (#1047).
 */
enum CaseStatus: string
{
    case Outpatient = 'outpatient';
    case Inpatient = 'inpatient';
    case PreInpatient = 'pre_inpatient';
    case PostInpatient = 'post_inpatient';
}
