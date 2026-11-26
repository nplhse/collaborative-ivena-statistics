<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Filter;

use App\Allocation\Application\Filter\ClosureAtAssignmentRule;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\ClosureAtAssignment;
use App\Allocation\Domain\Enum\ClosureCareLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClosureAtAssignmentRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{ClosureCareLevel, list<AllocationUrgency>}>
     */
    public static function careLevels(): iterable
    {
        yield 'SK1 closes only SK1' => [ClosureCareLevel::EMERGENCY, [AllocationUrgency::EMERGENCY]];
        yield 'SK2 closes only SK2' => [ClosureCareLevel::INPATIENT, [AllocationUrgency::INPATIENT]];
        yield 'SK3 closes only SK3' => [ClosureCareLevel::OUTPATIENT, [AllocationUrgency::OUTPATIENT]];
        yield 'other closes SK1 to SK3' => [ClosureCareLevel::OTHER, AllocationUrgency::cases()];
    }

    /**
     * @param list<AllocationUrgency> $expected
     */
    #[DataProvider('careLevels')]
    public function testCareLevelClosesItsUrgencies(ClosureCareLevel $level, array $expected): void
    {
        self::assertSame($expected, $level->closesUrgencies());
    }

    public function testCareLevelConditionListsEveryCareLevel(): void
    {
        self::assertSame(
            "((cl.care_level = 'emergency' AND a.urgency IN (1))"
            ." OR (cl.care_level = 'inpatient' AND a.urgency IN (2))"
            ." OR (cl.care_level = 'outpatient' AND a.urgency IN (3))"
            ." OR (cl.care_level = 'other' AND a.urgency IN (1, 2, 3)))",
            ClosureAtAssignmentRule::careLevelClosesUrgency('cl.care_level', 'a.urgency'),
        );
    }

    public function testFromQueryIgnoresUnknownValues(): void
    {
        self::assertSame(ClosureAtAssignment::OPEN, ClosureAtAssignmentRule::fromQuery('open'));
        self::assertNull(ClosureAtAssignmentRule::fromQuery('maybe'));
        self::assertNull(ClosureAtAssignmentRule::fromQuery(null));
    }
}
