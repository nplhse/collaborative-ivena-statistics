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
            occasion: '22',
            departmentWasClosed: 1,
        );

        $criteria = $dto->toListFilterCriteria();

        self::assertSame(11, $criteria->assignment);
        self::assertSame('22', $criteria->occasion);
        self::assertSame(1, $criteria->departmentWasClosed);
    }

    public function testToListFilterCriteriaMapsOptionalRelationSentinels(): void
    {
        $dto = new AllocationQueryParametersDTO(
            secondaryIndication: 'none',
            secondaryTransport: 'none',
            occasion: 'none',
        );

        $criteria = $dto->toListFilterCriteria();

        self::assertSame('none', $criteria->secondaryTransport);
        self::assertSame('none', $criteria->occasion);
        self::assertSame('none', $criteria->secondaryIndication);
    }

    public function testToListFilterCriteriaMapsInfectiousTriState(): void
    {
        $absent = new AllocationQueryParametersDTO(isInfectious: '0')->toListFilterCriteria();
        $present = new AllocationQueryParametersDTO(isInfectious: '1')->toListFilterCriteria();
        $unset = new AllocationQueryParametersDTO(isInfectious: '')->toListFilterCriteria();

        self::assertSame(0, $absent->isInfectious);
        self::assertSame(1, $present->isInfectious);
        self::assertNull($unset->isInfectious);
    }

    public function testToListFilterCriteriaMapsInfectionSelectSentinelsAndIds(): void
    {
        $none = new AllocationQueryParametersDTO(infection: 'none')->toListFilterCriteria();
        $any = new AllocationQueryParametersDTO(infection: 'any')->toListFilterCriteria();
        $specific = new AllocationQueryParametersDTO(infection: '9')->toListFilterCriteria();

        self::assertSame(0, $none->isInfectious);
        self::assertNull($none->infection);
        self::assertSame(1, $any->isInfectious);
        self::assertNull($any->infection);
        self::assertSame(1, $specific->isInfectious);
        self::assertSame(9, $specific->infection);
    }
}
