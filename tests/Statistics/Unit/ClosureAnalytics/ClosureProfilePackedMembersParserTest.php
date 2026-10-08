<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfilePackedMembersParser;
use PHPUnit\Framework\TestCase;

final class ClosureProfilePackedMembersParserTest extends TestCase
{
    public function testParsePackedMembers(): void
    {
        $packed = implode("\x1e", [
            implode("\x1f", ['Speciality A', 'Department A', 'emergency', 'no_bed_capacity']),
            implode("\x1f", ['Speciality B', 'Department B', 'inpatient', 'staff_shortage']),
        ]);

        $members = new ClosureProfilePackedMembersParser()->parse($packed);

        self::assertCount(2, $members);
        self::assertSame('Speciality A', $members[0]->specialityName);
        self::assertSame('Department A', $members[0]->departmentName);
        self::assertSame('emergency', $members[0]->careLevel);
        self::assertSame('no_bed_capacity', $members[0]->reason);
    }
}
