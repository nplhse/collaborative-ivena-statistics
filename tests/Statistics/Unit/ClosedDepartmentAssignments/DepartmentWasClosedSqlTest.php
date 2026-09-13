<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosedDepartmentAssignments;

use App\Statistics\Application\Mapping\DepartmentWasClosedSql;
use PHPUnit\Framework\TestCase;

final class DepartmentWasClosedSqlTest extends TestCase
{
    public function testClosedAndRegularPredicates(): void
    {
        self::assertSame('department_was_closed IS TRUE', DepartmentWasClosedSql::closed());
        self::assertSame('department_was_closed IS NOT TRUE', DepartmentWasClosedSql::regular());
        self::assertSame('p.department_was_closed IS TRUE', DepartmentWasClosedSql::closed('p'));
        self::assertSame('p.department_was_closed IS NOT TRUE', DepartmentWasClosedSql::regular('p'));
    }
}
