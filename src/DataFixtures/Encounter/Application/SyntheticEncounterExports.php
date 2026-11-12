<?php

declare(strict_types=1);

namespace App\DataFixtures\Encounter\Application;

/**
 * Splits generated visits into exports as a hospital would upload them (#1052, ADR 028).
 *
 * The default is two overlapping exports of a month: the first covers days 1–20, the second day 11
 * up to the last visit. Visits of days 11–20 are in both, so the second upload counts them as known
 * visits. A visit with a late assessment appears without first assessment contact (and triage) in the
 * first export that contains it and with it in every later one: its anchor and fingerprint change, so
 * the later upload inserts it again and reports a suspected duplicate (same case key, anchor within
 * 15 minutes).
 */
final class SyntheticEncounterExports
{
    /**
     * @return list<EdVisitExport>
     */
    public static function overlapping(GeneratedEncounters $generated, \DateTimeImmutable $monthStart): array
    {
        $start = $monthStart->modify('first day of this month');

        return self::split($generated, [
            ['name' => $start->format('Y-m').'_part1', 'from' => null, 'to' => $start->modify('+19 days')->format('Y-m-d')],
            ['name' => $start->format('Y-m').'_part2', 'from' => $start->modify('+10 days')->format('Y-m-d'), 'to' => null],
        ]);
    }

    /**
     * @param list<array{name: string, from: ?string, to: ?string}> $ranges days `Y-m-d`, inclusive; null is open, in upload order
     *
     * @return list<EdVisitExport>
     */
    public static function split(GeneratedEncounters $generated, array $ranges): array
    {
        $seen = [];
        $exports = [];
        foreach ($ranges as $range) {
            $rows = [];
            foreach ($generated->visits as $index => $visit) {
                $day = $visit->day();
                if ((null !== $range['from'] && $day < $range['from']) || (null !== $range['to'] && $day > $range['to'])) {
                    continue;
                }

                $rows[] = ['visit' => $visit, 'cells' => $visit->cellsAsExported(isset($seen[$index]))];
                $seen[$index] = true;
            }

            $exports[] = new EdVisitExport($range['name'], $rows);
        }

        return $exports;
    }
}
