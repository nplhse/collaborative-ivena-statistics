<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Validation;

use App\Encounter\Application\Validation\PlausibilityValidationGroup;
use App\Encounter\Application\Validation\PlausibilityValidationReport;
use App\Encounter\Domain\Enum\ValidationStratum;
use App\Encounter\Domain\Enum\ValidationVerdict;
use PHPUnit\Framework\TestCase;

final class PlausibilityValidationReportTest extends TestCase
{
    public function testBonusDecidedPairsAreAGroupOfTheirOwnPerVersionAndSnapshot(): void
    {
        $automatic = ValidationStratum::Automatic;
        $report = PlausibilityValidationReport::build([
            // 1.1.0, no snapshot: 2 correct → 2 / 2.
            ...$this->items($automatic, '1.1.0', null, false, [ValidationVerdict::Correct, ValidationVerdict::Correct]),
            // 1.2.0, snapshot 7, bonus not decisive: 3 correct, 1 wrong → 3 / 4 = 0.75.
            ...$this->items($automatic, '1.2.0', 7, false, [ValidationVerdict::Correct, ValidationVerdict::Correct, ValidationVerdict::Correct, ValidationVerdict::Wrong]),
            // 1.2.0, snapshot 7, decided by the bonus: 1 correct, 1 wrong (another visit fits), 1 unclear, 1 open → 1 / 2.
            ...$this->items($automatic, '1.2.0', 7, true, [ValidationVerdict::Correct, ValidationVerdict::Wrong, ValidationVerdict::Unclear, null], replaced: true),
            // 1.2.0, pass 1 (no snapshot): 1 correct.
            ...$this->items($automatic, '1.2.0', null, false, [ValidationVerdict::Correct]),
            // 1.2.0, snapshot 7, decisive proposal: 1 wrong → 0 / 1.
            ...$this->items(ValidationStratum::Proposal, '1.2.0', 7, true, [ValidationVerdict::Wrong]),
            // 1.2.0, drawn before the flag was recorded: its own group, nothing guessed; 1 wrong → 0 / 1.
            ...$this->items($automatic, '1.2.0', null, null, [ValidationVerdict::Wrong]),
            // Unlinked cases have no pair and stay out.
            ...$this->items(ValidationStratum::UnlinkedAllocation, '1.2.0', null, false, [ValidationVerdict::Missed]),
        ]);

        self::assertSame(
            ['1.2.0|unrecorded|-', '1.2.0|none|-', '1.2.0|bonus|7', '1.2.0|decisive|7', '1.1.0|none|-'],
            array_map(static fn (PlausibilityValidationGroup $group): string => $group->matcherVersion.'|'.$group->kind.'|'.($group->snapshotId ?? '-'), $report->groups),
        );

        [$unrecorded, $passOne, $notDecisive, $decisive, $older] = $report->groups;
        self::assertSame([1, 0], [$unrecorded->rows[0]->sampled, $unrecorded->rows[0]->positive]);
        self::assertSame(1.0, $passOne->rows[0]->share?->share);
        self::assertSame(0.75, $notDecisive->rows[0]->share?->share);

        self::assertCount(2, $decisive->rows);
        $decisiveLinks = $decisive->rows[0];
        self::assertSame(ValidationStratum::Automatic, $decisiveLinks->stratum);
        self::assertSame([4, 3, 1, 1, 1, 1, 1], [$decisiveLinks->sampled, $decisiveLinks->judged, $decisiveLinks->positive, $decisiveLinks->negative, $decisiveLinks->negativeOther, $decisiveLinks->unclear, $decisiveLinks->open()]);
        self::assertSame(0.5, $decisiveLinks->share?->share);
        self::assertNull($decisiveLinks->population);
        self::assertSame(ValidationStratum::Proposal, $decisive->rows[1]->stratum);
        self::assertSame(0.0, $decisive->rows[1]->share?->share);

        self::assertSame(1.0, $older->rows[0]->share?->share);
    }

    public function testADecisiveFlagWithoutSnapshotCountsAsNoBonus(): void
    {
        $report = PlausibilityValidationReport::build($this->items(ValidationStratum::Automatic, '1.2.0', null, true, [null]));

        self::assertCount(1, $report->groups);
        self::assertNull($report->groups[0]->snapshotId);
        self::assertSame(PlausibilityValidationReport::NONE, $report->groups[0]->kind);
    }

    public function testNoPairsGiveNoGroups(): void
    {
        self::assertSame([], PlausibilityValidationReport::build([])->groups);
    }

    /**
     * @param list<?ValidationVerdict> $verdicts
     *
     * @return list<array{stratum: ValidationStratum, matcher_version: string, snapshot_id: ?int, decisive: ?bool, verdict: ?ValidationVerdict, replaced: bool}>
     */
    private function items(ValidationStratum $stratum, string $version, ?int $snapshot, ?bool $decisive, array $verdicts, bool $replaced = false): array
    {
        return array_map(static fn (?ValidationVerdict $verdict): array => [
            'stratum' => $stratum,
            'matcher_version' => $version,
            'snapshot_id' => $snapshot,
            'decisive' => $decisive,
            'verdict' => $verdict,
            'replaced' => $replaced && ValidationVerdict::Wrong === $verdict,
        ], $verdicts);
    }
}
