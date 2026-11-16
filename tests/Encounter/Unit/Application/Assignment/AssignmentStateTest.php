<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Assignment;

use App\Encounter\Application\Assignment\AssignmentState;
use App\Encounter\Contract\Assignment\AssignmentCandidate;
use App\Encounter\Contract\Assignment\AssignmentKind;
use App\Encounter\Contract\Assignment\AssignmentResult;
use App\Encounter\Contract\Assignment\CandidateReason;
use PHPUnit\Framework\TestCase;

final class AssignmentStateTest extends TestCase
{
    public function testCandidatesAreComparedAsAnUnorderedSet(): void
    {
        $seen = new AssignmentState(AssignmentKind::Ambiguous, [7, 3]);

        self::assertTrue($seen->equals(new AssignmentState(AssignmentKind::Ambiguous, [3, 7])));
        self::assertTrue($seen->equals(new AssignmentState(AssignmentKind::Ambiguous, [3, 7, 3])));
        self::assertFalse($seen->equals(new AssignmentState(AssignmentKind::Ambiguous, [3, 7, 9])));
        self::assertFalse($seen->equals(new AssignmentState(AssignmentKind::Ambiguous, [3])));
    }

    public function testTheKindIsPartOfTheState(): void
    {
        self::assertFalse(new AssignmentState(AssignmentKind::Ambiguous, [3, 7])->equals(new AssignmentState(AssignmentKind::Contradictory, [7, 3])));
    }

    public function testReasonsAreNotCompared(): void
    {
        $result = AssignmentResult::contradictory([new AssignmentCandidate(7, CandidateReason::Allocation), new AssignmentCandidate(3, CandidateReason::CaseKey)]);

        self::assertTrue(AssignmentState::of($result)->equals(AssignmentState::stored(AssignmentKind::Contradictory, [
            ['encounter_id' => 3, 'reason' => 'allocation'],
            ['encounter_id' => 7, 'reason' => 'case_key'],
        ])));
    }

    public function testTheFormCarriesSortedIds(): void
    {
        $state = new AssignmentState(AssignmentKind::Ambiguous, [12, 4, 9]);

        self::assertSame('4,9,12', $state->encounterIdList());
        $read = AssignmentState::fromForm('ambiguous', '4,9,12');
        self::assertNotNull($read);
        self::assertTrue($state->equals($read));
    }

    public function testOnlyWellFormedConflictStatesAreReadFromAForm(): void
    {
        self::assertNull(AssignmentState::fromForm('assigned', '4'));
        self::assertNull(AssignmentState::fromForm('no_candidate', ''));
        self::assertNull(AssignmentState::fromForm('unknown', '4'));
        self::assertNull(AssignmentState::fromForm('ambiguous', '4,x'));
        self::assertNull(AssignmentState::fromForm('ambiguous', '4,-1'));
    }
}
