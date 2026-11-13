<?php

declare(strict_types=1);

namespace App\Encounter\Application\Validation;

use App\Encounter\Domain\Enum\ValidationStratum;

/**
 * @psalm-api Read by the validation templates.
 *
 * One line of the validation report (#1057): a stratum, for pairs one score band of it.
 *
 * Pairs: `positive` = correct, `negative` = wrong; the share is the observed share correct among the
 * decisive verdicts; `negativeOther` of the wrong ones had another visit linked instead, the rest
 * (`negativeNoPartner()`) had no partner. Unlinked cases: `positive` = missed, `negative` = no partner; the share is the
 * observed share missed. Unclear verdicts and open items stay out of the share.
 */
final readonly class ValidationReportRow
{
    public function __construct(
        public ValidationStratum $stratum,
        public ?string $band,
        /** Cases of the stratum (band) in the period when the sample was drawn; null in combined reports. */
        public ?int $population,
        public int $sampled,
        public int $judged,
        public int $positive,
        public int $negative,
        public int $unclear,
        public ?ShareEstimate $share,
        /** Pairs: wrong with another visit linked instead; 0 for unlinked cases. */
        public int $negativeOther = 0,
    ) {
    }

    /** Pairs: wrong without another visit (no partner). */
    public function negativeNoPartner(): int
    {
        return $this->negative - $this->negativeOther;
    }

    public function open(): int
    {
        return $this->sampled - $this->judged;
    }
}
