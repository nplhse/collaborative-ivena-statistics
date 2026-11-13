<?php

declare(strict_types=1);

namespace App\Encounter\Application\Validation;

use App\Encounter\Domain\Enum\ValidationStratum;
use App\Encounter\Domain\Enum\ValidationVerdict;

/**
 * @psalm-api Read by the validation templates.
 *
 * Report of match validation verdicts (#1057): per stratum and score band the observed share correct
 * (pairs) or missed (unlinked cases) with its Wilson interval, and per pair stratum an estimate over all
 * bands weighted by their population. Pure.
 *
 * "Wrong" pairs are split into "another visit fits" (a replacement was linked) and "no partner"; the share
 * correct counts both as wrong.
 *
 * The weighted estimate is Σ population_b × share_b / Σ population_b. It is only given when every band
 * with population has at least one decisive verdict; otherwise a band would silently drop out.
 *
 * The shares are observations on the sample. They calibrate the score; until then the score stays
 * "agreement" (ADR 030).
 */
final readonly class ValidationReport
{
    /**
     * @param list<ValidationReportRow> $rows     strata in enum order, bands from highest down
     * @param array<string, float|null> $weighted stratum value => weighted share of the pair strata
     */
    private function __construct(
        public array $rows,
        public array $weighted,
    ) {
    }

    /**
     * @param list<array{stratum: ValidationStratum, band: ?string, verdict: ?ValidationVerdict, replaced?: bool}> $items
     * @param array<string, int>|null                                                                              $population per cell key ({@see self::cellKey()}); null for a combined report without population
     */
    public static function build(ScoreBands $bands, array $items, ?array $population): self
    {
        /** @var array<string, array{sampled: int, judged: int, positive: int, negative: int, negative_other: int, unclear: int}> $counts */
        $counts = [];
        foreach ($items as $item) {
            $key = self::cellKey($item['stratum'], $item['band']);
            $counts[$key] ??= ['sampled' => 0, 'judged' => 0, 'positive' => 0, 'negative' => 0, 'negative_other' => 0, 'unclear' => 0];
            ++$counts[$key]['sampled'];
            $verdict = $item['verdict'];
            if (null === $verdict) {
                continue;
            }

            ++$counts[$key]['judged'];
            match ($verdict) {
                ValidationVerdict::Correct, ValidationVerdict::Missed => ++$counts[$key]['positive'],
                ValidationVerdict::Wrong, ValidationVerdict::NoPartner => ++$counts[$key]['negative'],
                ValidationVerdict::Unclear => ++$counts[$key]['unclear'],
            };
            if (ValidationVerdict::Wrong === $verdict && ($item['replaced'] ?? false)) {
                ++$counts[$key]['negative_other'];
            }
        }

        $rows = [];
        $weighted = [];
        foreach (ValidationStratum::cases() as $stratum) {
            if (!$stratum->isPair()) {
                $rows[] = self::row($stratum, null, $counts, $population);

                continue;
            }

            $labels = array_column($bands->bands(), 'label');
            foreach ([...array_keys($counts), ...array_keys($population ?? [])] as $key) {
                [$cellStratum, $band] = array_pad(explode('|', (string) $key, 2), 2, null);
                if ($cellStratum === $stratum->value && null !== $band && !\in_array($band, $labels, true)) {
                    $labels[] = $band;
                }
            }

            $weight = 0;
            $sum = 0.0;
            $complete = null !== $population;
            foreach ($labels as $label) {
                $row = self::row($stratum, $label, $counts, $population);
                if (0 === $row->sampled && 0 === ($row->population ?? 0)) {
                    continue;
                }

                $rows[] = $row;
                if (($row->population ?? 0) > 0) {
                    if (!$row->share instanceof ShareEstimate) {
                        $complete = false;
                    } else {
                        $weight += (int) $row->population;
                        $sum += (float) $row->population * $row->share->share;
                    }
                }
            }

            $weighted[$stratum->value] = $complete && $weight > 0 ? $sum / (float) $weight : null;
        }

        return new self($rows, $weighted);
    }

    public static function cellKey(ValidationStratum $stratum, ?string $band): string
    {
        return null === $band ? $stratum->value : $stratum->value.'|'.$band;
    }

    /**
     * @param array<string, array{sampled: int, judged: int, positive: int, negative: int, negative_other: int, unclear: int}> $counts
     * @param array<string, int>|null                                                                                          $population
     */
    private static function row(ValidationStratum $stratum, ?string $band, array $counts, ?array $population): ValidationReportRow
    {
        $key = self::cellKey($stratum, $band);
        $count = $counts[$key] ?? ['sampled' => 0, 'judged' => 0, 'positive' => 0, 'negative' => 0, 'negative_other' => 0, 'unclear' => 0];

        return new ValidationReportRow(
            stratum: $stratum,
            band: $band,
            population: null === $population ? null : ($population[$key] ?? 0),
            sampled: $count['sampled'],
            judged: $count['judged'],
            positive: $count['positive'],
            negative: $count['negative'],
            unclear: $count['unclear'],
            share: ShareEstimate::of($count['positive'], $count['positive'] + $count['negative']),
            negativeOther: $count['negative_other'],
        );
    }
}
