<?php

declare(strict_types=1);

namespace App\Encounter\Application\Validation;

/**
 * @psalm-api Read by the validation overview template.
 *
 * One group of {@see PlausibilityValidationReport}: a matcher version, a plausibility snapshot (null: none recorded)
 * and whether the bonus decided the pairs, with one row per pair stratum.
 */
final readonly class PlausibilityValidationGroup
{
    /**
     * @param list<ValidationReportRow> $rows automatic links, then proposals; only strata with sampled pairs
     */
    public function __construct(
        public string $matcherVersion,
        public ?int $snapshotId,
        public bool $decisive,
        public array $rows,
    ) {
    }
}
