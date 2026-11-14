<?php

declare(strict_types=1);

namespace App\Encounter\Application\Validation;

use App\Encounter\Domain\Enum\ValidationStratum;
use App\Encounter\Domain\Enum\ValidationVerdict;

/**
 * @psalm-api Read by the validation overview template.
 *
 * Verdicts on sampled pairs by matcher version, plausibility snapshot and whether the bonus was decisive (#1156,
 * ADR 030 amendment, ADR 033). Pure.
 *
 * A group is one matcher version (of the pair as sampled, else of its sample) and one of: not recorded (drawn before
 * #1156, nothing is guessed), no snapshot (pass 1, versions before 1.2.0, no active snapshot), a snapshot whose bonus
 * did not decide the pair, or a snapshot whose bonus decided it. Within a group each pair stratum (automatic link, proposal) is one row counted as in
 * {@see ValidationReport}, without score bands, population or weighting: samples can overlap, and the point is to set
 * the bonus-decided pairs against the rest. Unlinked cases have no pair and stay out.
 */
final readonly class PlausibilityValidationReport
{
    public const string UNRECORDED = 'unrecorded';

    public const string NONE = 'none';

    public const string BONUS = 'bonus';

    public const string DECISIVE = 'decisive';

    /**
     * @param list<PlausibilityValidationGroup> $groups
     */
    private function __construct(
        public array $groups,
    ) {
    }

    /**
     * @param list<array{stratum: ValidationStratum, matcher_version: string, snapshot_id: ?int, decisive: ?bool, verdict: ?ValidationVerdict, replaced: bool}> $items
     */
    public static function build(array $items): self
    {
        /** @var array<string, array{version: string, kind: string, snapshot: ?int, counts: array<string, array{sampled: int, judged: int, positive: int, negative: int, negative_other: int, unclear: int}>}> $byGroup */
        $byGroup = [];
        foreach ($items as $item) {
            $stratum = $item['stratum'];
            if (!$stratum->isPair()) {
                continue;
            }

            // Without snapshot no bonus was looked up, so nothing can have been decisive.
            $kind = match (true) {
                null === $item['decisive'] => self::UNRECORDED,
                null === $item['snapshot_id'] => self::NONE,
                $item['decisive'] => self::DECISIVE,
                default => self::BONUS,
            };
            $snapshot = self::UNRECORDED === $kind ? null : $item['snapshot_id'];
            $key = $item['matcher_version'].'|'.$kind.'|'.($snapshot ?? '-');
            $byGroup[$key] ??= ['version' => $item['matcher_version'], 'kind' => $kind, 'snapshot' => $snapshot, 'counts' => []];
            $count = $byGroup[$key]['counts'][$stratum->value] ?? ['sampled' => 0, 'judged' => 0, 'positive' => 0, 'negative' => 0, 'negative_other' => 0, 'unclear' => 0];
            ++$count['sampled'];
            $verdict = $item['verdict'];
            if (null !== $verdict) {
                ++$count['judged'];
                match ($verdict) {
                    ValidationVerdict::Correct, ValidationVerdict::Missed => ++$count['positive'],
                    ValidationVerdict::Wrong, ValidationVerdict::NoPartner => ++$count['negative'],
                    ValidationVerdict::Unclear => ++$count['unclear'],
                };
                if (ValidationVerdict::Wrong === $verdict && $item['replaced']) {
                    ++$count['negative_other'];
                }
            }

            $byGroup[$key]['counts'][$stratum->value] = $count;
        }

        // Newest matcher version first; within it not recorded, no snapshot, then snapshots by id, each bonus-decided
        // pairs last.
        $order = array_flip([self::UNRECORDED, self::NONE, self::BONUS, self::DECISIVE]);
        uasort($byGroup, static fn (array $a, array $b): int => [version_compare($b['version'], $a['version']), $a['snapshot'] ?? 0, $order[$a['kind']]] <=> [0, $b['snapshot'] ?? 0, $order[$b['kind']]]);

        $groups = [];
        foreach ($byGroup as $group) {
            $rows = [];
            foreach (ValidationStratum::cases() as $stratum) {
                $count = $group['counts'][$stratum->value] ?? null;
                if (null === $count) {
                    continue;
                }

                $rows[] = new ValidationReportRow(
                    stratum: $stratum,
                    band: null,
                    population: null,
                    sampled: $count['sampled'],
                    judged: $count['judged'],
                    positive: $count['positive'],
                    negative: $count['negative'],
                    unclear: $count['unclear'],
                    share: ShareEstimate::of($count['positive'], $count['positive'] + $count['negative']),
                    negativeOther: $count['negative_other'],
                );
            }

            $groups[] = new PlausibilityValidationGroup($group['version'], $group['kind'], $group['snapshot'], $rows);
        }

        return new self($groups);
    }
}
