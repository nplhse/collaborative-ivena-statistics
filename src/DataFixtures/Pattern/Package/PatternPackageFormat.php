<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Package;

use App\DataFixtures\Pattern\Application\DefaultDistributionPatterns;

/**
 * Identity of the allocation pattern package format (ADR 022).
 *
 * Only the current version is accepted; a format change raises it. Structural (hospital capability)
 * patterns get a format of their own with the same meta shape (#876).
 */
final class PatternPackageFormat
{
    public const string NAME = 'ivena-distribution-patterns';

    public const int VERSION = 1;

    public const string CHECKSUM_ALGORITHM = 'sha256';

    /** Shares of a table may miss 1 by this much, so hand-written shares rounded to four places pass. */
    public const float SUM_TOLERANCE = 0.002;

    /** Flags a segment or an indication group may set; `requires_resus` of a group is its `resus_factor`. */
    public const array FLAGS = [
        'requires_resus',
        'requires_cathlab',
        'is_cpr',
        'is_ventilated',
        'is_shock',
        'is_pregnant',
        'is_work_accident',
        'is_with_physician',
    ];

    public const array PERCENTILES = ['p25', 'p50', 'p75', 'p90'];

    /** Counts in `meta.counts`. */
    public const array COUNTS = ['segments', 'indication_groups', 'indications'];

    /**
     * The segments the generator draws hospitals into; a package has a pattern for each of them.
     *
     * @return list<string>
     */
    public static function segments(): array
    {
        return DefaultDistributionPatterns::names();
    }
}
