<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileKind;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileRef;
use PHPUnit\Framework\TestCase;

final class ClosureProfileRefTest extends TestCase
{
    public function testQueryRoundTripKeepsTheProfileDefinition(): void
    {
        $speciality = ClosureProfileRef::speciality(40, 9);
        $department = ClosureProfileRef::department(40, 3);
        $hospital = ClosureProfileRef::hospital(40);
        $unit = ClosureProfileRef::closureUnit(40, 'Ward A: east');
        $group = ClosureProfileRef::group(40, md5('9:3:emergency:no_bed_capacity'));

        self::assertSame($speciality->toQuery(), ClosureProfileRef::fromQuery($speciality->toQuery())?->toQuery());
        self::assertSame($department->toQuery(), ClosureProfileRef::fromQuery($department->toQuery())?->toQuery());
        self::assertSame($hospital->toQuery(), ClosureProfileRef::fromQuery($hospital->toQuery())?->toQuery());
        self::assertSame($unit->toQuery(), ClosureProfileRef::fromQuery($unit->toQuery())?->toQuery());
        self::assertSame('Ward A: east', ClosureProfileRef::fromQuery($unit->toQuery())->key);
        self::assertSame($group->toQuery(), ClosureProfileRef::fromQuery($group->toQuery())?->toQuery());
        self::assertSame(ClosureProfileKind::Group, $group->kind);
        self::assertSame(ClosureProfileKind::Department, ClosureProfileRef::fromQuery('department:0:3')?->kind);
        self::assertNull(ClosureProfileRef::fromQuery('group:40:not-an-md5'));
    }
}
