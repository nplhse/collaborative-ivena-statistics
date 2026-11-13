<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * Verdict of a reviewer on a validation item (#1057).
 *
 * Pairs: `correct` (allocation and visit belong together), `wrong` (they do not; the pair is rejected as
 * well), `unclear`. Unlinked allocations and visits: `missed` (a partner exists in the data but was not
 * linked or proposed), `no_partner` (no partner in the data), `unclear`. Unclear items count as judged
 * but stay out of shares.
 */
enum ValidationVerdict: string
{
    case Correct = 'correct';
    case Wrong = 'wrong';
    case Unclear = 'unclear';
    case Missed = 'missed';
    case NoPartner = 'no_partner';
}
