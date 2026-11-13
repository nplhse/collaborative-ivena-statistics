<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Validation;

use App\Encounter\Application\Validation\ScoreBands;
use App\Encounter\Application\Validation\ValidationReport;
use App\Encounter\Application\Validation\ValidationReportRow;
use App\Encounter\Domain\Enum\ValidationStratum;
use App\Encounter\Domain\Enum\ValidationVerdict;
use PHPUnit\Framework\TestCase;

final class ValidationReportTest extends TestCase
{
    private const string TOP = '0.99–1.00';

    private const string HIGH = '0.90–0.99';

    private const string MID = '0.70–0.90';

    public function testSharesPerBandAndWeightedByPopulation(): void
    {
        $automatic = ValidationStratum::Automatic;
        $report = ValidationReport::build(new ScoreBands(), [
            // Top band: 3 correct, 1 wrong, 1 open → 3 / 4 = 0.75.
            ...$this->items($automatic, self::TOP, [ValidationVerdict::Correct, ValidationVerdict::Correct, ValidationVerdict::Correct, ValidationVerdict::Wrong, null]),
            // High band: 2 correct, 1 unclear → 2 / 2 = 1.0.
            ...$this->items($automatic, self::HIGH, [ValidationVerdict::Correct, ValidationVerdict::Correct, ValidationVerdict::Unclear]),
            // Proposals: drawn but not judged yet.
            ...$this->items(ValidationStratum::Proposal, self::MID, [null, null]),
            // Unlinked allocations: missed, no partner, unclear → 1 / 2 missed.
            ...$this->items(ValidationStratum::UnlinkedAllocation, null, [ValidationVerdict::Missed, ValidationVerdict::NoPartner, ValidationVerdict::Unclear]),
        ], [
            'automatic|'.self::TOP => 100,
            'automatic|'.self::HIGH => 20,
            'proposal|'.self::MID => 5,
            'unlinked_allocation' => 40,
            'unlinked_visit' => 0,
        ]);

        $top = $this->row($report, $automatic, self::TOP);
        self::assertSame([100, 5, 4, 3, 1, 0, 1], [$top->population, $top->sampled, $top->judged, $top->positive, $top->negative, $top->unclear, $top->open()]);
        self::assertSame(0.75, $top->share?->share);

        $high = $this->row($report, $automatic, self::HIGH);
        self::assertSame([20, 3, 3, 2, 0, 1], [$high->population, $high->sampled, $high->judged, $high->positive, $high->negative, $high->unclear]);
        self::assertSame(1.0, $high->share?->share);

        // (100 · 0.75 + 20 · 1.0) / 120 = 95 / 120.
        self::assertEqualsWithDelta(95 / 120, $report->weighted['automatic'], 1e-9);
        // A band with population but no decisive verdict: no weighted estimate.
        self::assertNull($report->weighted['proposal']);
        self::assertNull($this->row($report, ValidationStratum::Proposal, self::MID)->share);

        $unlinked = $this->row($report, ValidationStratum::UnlinkedAllocation, null);
        self::assertSame([40, 3, 1, 1, 1], [$unlinked->population, $unlinked->sampled, $unlinked->positive, $unlinked->negative, $unlinked->unclear]);
        self::assertSame(0.5, $unlinked->share?->share);

        // Bands without population and without items are left out; unlinked strata always show.
        self::assertSame(
            ['automatic|'.self::TOP, 'automatic|'.self::HIGH, 'proposal|'.self::MID, 'unlinked_allocation', 'unlinked_visit'],
            array_map(static fn (ValidationReportRow $row): string => ValidationReport::cellKey($row->stratum, $row->band), $report->rows),
        );
    }

    public function testCombinedReportHasNoPopulationAndNoWeightedEstimate(): void
    {
        $report = ValidationReport::build(new ScoreBands(), $this->items(ValidationStratum::Automatic, self::TOP, [ValidationVerdict::Correct]), null);

        self::assertNull($this->row($report, ValidationStratum::Automatic, self::TOP)->population);
        self::assertNull($report->weighted['automatic']);
    }

    /**
     * @param list<?ValidationVerdict> $verdicts
     *
     * @return list<array{stratum: ValidationStratum, band: ?string, verdict: ?ValidationVerdict}>
     */
    private function items(ValidationStratum $stratum, ?string $band, array $verdicts): array
    {
        return array_map(static fn (?ValidationVerdict $verdict): array => ['stratum' => $stratum, 'band' => $band, 'verdict' => $verdict], $verdicts);
    }

    private function row(ValidationReport $report, ValidationStratum $stratum, ?string $band): ValidationReportRow
    {
        foreach ($report->rows as $row) {
            if ($row->stratum === $stratum && $row->band === $band) {
                return $row;
            }
        }

        self::fail(sprintf('No row %s %s.', $stratum->value, $band ?? ''));
    }
}
