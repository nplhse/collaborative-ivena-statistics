<?php

declare(strict_types=1);

namespace App\Encounter\Application\Validation;

/**
 * What recording a validation verdict changed besides the verdict (#1057).
 *
 * - `recorded`: the verdict only
 * - `rejected`: the sampled pair was rejected ("wrong, no partner")
 * - `confirmed`: the sampled pair was confirmed ("correct")
 * - `linked`: the chosen partner was linked (confirmed; for a pair the sampled pair was rejected first)
 * - `swapped`: the chosen partner was linked through the review's swap proposal
 */
final readonly class ValidationRecordResult
{
    public const string RECORDED = 'recorded';

    public const string REJECTED = 'rejected';

    public const string CONFIRMED = 'confirmed';

    public const string LINKED = 'linked';

    public const string SWAPPED = 'swapped';

    public function __construct(public string $outcome)
    {
    }
}
