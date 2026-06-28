<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\UI\Http\DTO;

use App\Allocation\UI\Http\DTO\AllocationQueryParametersDTO;
use PHPUnit\Framework\TestCase;

final class AllocationQueryParametersDTOTest extends TestCase
{
    public function testToListFilterCriteriaMapsAssignmentOccasionAndDepartmentWasClosed(): void
    {
        $dto = new AllocationQueryParametersDTO(
            assignment: 11,
            occasion: 22,
            departmentWasClosed: 1,
        );

        $criteria = $dto->toListFilterCriteria();

        self::assertSame(11, $criteria->assignment);
        self::assertSame(22, $criteria->occasion);
        self::assertSame(1, $criteria->departmentWasClosed);
    }
}
