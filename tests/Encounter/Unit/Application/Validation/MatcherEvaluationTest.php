<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Validation;

use App\Encounter\Application\Matching\PlannedMatch;
use App\Encounter\Application\Validation\MatcherEvaluation;
use App\Encounter\Application\Validation\ScoreBands;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\MatchStatus;
use PHPUnit\Framework\TestCase;

final class MatcherEvaluationTest extends TestCase
{
    public function testCountsCorrectWrongAndUnknownPerStatusAndBand(): void
    {
        $plan = [
            $this->match(1, 11, MatchStatus::AutoAccepted, 0.9697),
            $this->match(2, 12, MatchStatus::AutoAccepted, 0.9412),
            $this->match(3, 13, MatchStatus::AutoAccepted, 0.9922),
            $this->match(4, 14, MatchStatus::Proposed, 0.80),
        ];
        // Verdicts: 1–11 correct, 2–12 wrong; 3–13 and 4–14 not judged.
        $evaluation = MatcherEvaluation::of(new ScoreBands(), $plan, ['1:11' => true, '2:12' => false]);

        self::assertSame([
            ['auto_accepted', '0.99–1.00', 1, 0, 0, 1],
            ['auto_accepted', '0.90–0.99', 2, 1, 1, 0],
            ['proposed', '0.70–0.90', 1, 0, 0, 1],
        ], array_map(static fn (array $row): array => [$row['status']->value, $row['band'], $row['pairs'], $row['correct'], $row['wrong'], $row['unknown']], $evaluation->rows));
        self::assertSame(0.5, $evaluation->rows[1]['share']?->share);
        self::assertNull($evaluation->rows[0]['share']);
        self::assertNull($evaluation->truePairs);
    }

    public function testRecallAgainstASyntheticTruth(): void
    {
        $plan = [
            $this->match(1, 11, MatchStatus::AutoAccepted, 0.9697),
            $this->match(2, 13, MatchStatus::AutoAccepted, 0.9697),
            $this->match(3, 12, MatchStatus::Proposed, 0.80),
        ];
        // Visit 11 belongs to 1, 12 to 3, 13 to 2; visit 15 to allocation 9, which was not replayed.
        $truth = [11 => 1, 12 => 3, 13 => 4, 15 => 9];
        $evaluation = MatcherEvaluation::of(new ScoreBands(), $plan, MatcherEvaluation::referenceFromTruth($plan, $truth), $truth, [1, 2, 3, 4]);

        // Automatic: 1–11 right, 2–13 wrong (13 belongs to 4). Proposal 3–12 right.
        self::assertSame([1, 1], [$evaluation->rows[0]['correct'], $evaluation->rows[0]['wrong']]);
        // True pairs among the replayed allocations: 11, 12, 13 → 3; automatic 1, automatic or proposal 2.
        self::assertSame([3, 1, 2], [$evaluation->truePairs, $evaluation->truePairsAutomatic, $evaluation->truePairsFound]);
    }

    private function match(int $allocation, int $encounter, MatchStatus $status, float $score): PlannedMatch
    {
        return new PlannedMatch($allocation, $encounter, $status, $score, 9, [], 1, AnchorSource::Admission, 5, false);
    }
}
