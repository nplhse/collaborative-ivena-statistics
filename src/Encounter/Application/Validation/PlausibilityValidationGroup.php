<?php

declare(strict_types=1);

namespace App\Encounter\Application\Validation;

/**
 * @psalm-api Read by the validation overview template.
 *
 * One group of {@see PlausibilityValidationReport}: a matcher version and its kind (`unrecorded`: drawn before the
 * bonus was recorded; `none`: no snapshot; `bonus`: a snapshot whose bonus did not decide; `decisive`: a snapshot whose
 * bonus decided), with the snapshot and one row per pair stratum.
 */
final readonly class PlausibilityValidationGroup
{
    /**
     * @param list<ValidationReportRow> $rows automatic links, then proposals; only strata with sampled pairs
     */
    public function __construct(
        public string $matcherVersion,
        public string $kind,
        public ?int $snapshotId,
        public array $rows,
    ) {
    }
}
