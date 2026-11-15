<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter\Poc;

use App\Tests\Fixtures\Plugin\InpatientStay\InpatientStayDimension;
use App\Tests\Support\Encounter\EdVisitFileBuilder;
use App\Tests\Support\Encounter\EncounterFileBuilder;

/**
 * Data of the end-to-end proof of #1274 (review "Integration test for a synthetic inpatient stay"), shared by
 * the functional test, the seed command for the browser check and the checked-in CSV.
 *
 * ED visits on 12 October 2026: A (case `A`, linked to allocation X at 08:30), B and C (one administrative
 * case `BC`; B linked to allocation Y at 09:30). The inpatient_stay file has four rows:
 *
 * | Row | Case | Expected                                              |
 * |-----|------|-------------------------------------------------------|
 * | 1   | A    | assigned to A                                         |
 * | 2   | A    | duplicate of row 1 (same fingerprint)                 |
 * | 3   | BC   | unassigned, conflict "ambiguous" (candidates B and C) |
 * | 4   | none | unassigned, no candidate                              |
 */
final class InpatientStayPoc
{
    public const string CSV = 'tests/Fixtures/Plugin/InpatientStay/fixtures/inpatient_stay_poc.csv';

    public static function edVisits(): string
    {
        return EdVisitFileBuilder::create()
            ->visit('A', '2026-10-12T08:15', 'A')
            ->visit('B', '2026-10-12T09:15', 'BC')
            ->visit('C', '2026-10-12T15:40', 'BC')
            ->build();
    }

    public static function stays(): string
    {
        return EncounterFileBuilder::for(InpatientStayDimension::SCHEMA, InpatientStayDimension::header(), InpatientStayDimension::FINGERPRINT_RULE, profile: 'synthetic-controlling')
            ->row(self::stay('1', 'A', '2026-10-12T10:05', 'I21.4', '2026-10-19T11:00'))
            ->row(self::stay('1', 'A', '2026-10-12T10:05', 'I21.4', '2026-10-19T11:00'))
            ->row(self::stay('3', 'BC', '2026-10-12T17:20', 'J18.9', '2026-10-25T09:30'))
            ->row(self::stay('4', 'none', '2026-10-13T08:00', 'S72.0', ''))
            ->build();
    }

    /**
     * @return array<string, string>
     */
    private static function stay(string $stay, string $case, string $admittedAt, string $diagnosis, string $dischargedAt): array
    {
        return [
            'stay_fingerprint' => hash('sha256', 'stay|'.$stay),
            'case_key' => EncounterFileBuilder::caseKey($case),
            'admitted_at' => $admittedAt,
            'discharge_diagnosis' => $diagnosis,
            'discharged_at' => $dischargedAt,
        ];
    }
}
