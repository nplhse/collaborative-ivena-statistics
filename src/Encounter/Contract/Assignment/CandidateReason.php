<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Assignment;

/**
 * Why an encounter is a candidate for a record.
 */
enum CandidateReason: string
{
    /** The encounter is linked to the allocation the record names. */
    case Allocation = 'allocation';

    /** The encounter has the record's case pseudonym. */
    case CaseKey = 'case_key';
}
