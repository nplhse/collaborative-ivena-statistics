<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Export;

use App\Allocation\Application\Export\OwnHospitalAllocationsExportFilterMapper;
use App\Allocation\UI\Form\Model\OwnHospitalAllocationsExportFormData;
use PHPUnit\Framework\TestCase;

final class OwnHospitalAllocationsExportFilterMapperTest extends TestCase
{
    private OwnHospitalAllocationsExportFilterMapper $mapper;

    #[\Override]
    protected function setUp(): void
    {
        $this->mapper = new OwnHospitalAllocationsExportFilterMapper();
    }

    public function testMapsFormDataToFilterWithHospitalSelection(): void
    {
        $data = new OwnHospitalAllocationsExportFormData();
        $data->dateFrom = new \DateTimeImmutable('2026-01-01');
        $data->dateTo = new \DateTimeImmutable('2026-01-31');
        $data->hospitals = [3, 5, 3];
        $data->urgency = 'red';
        $data->requiresResus = true;

        $filter = $this->mapper->fromFormData($data);

        self::assertSame('2026-01-01', $filter->dateFrom->format('Y-m-d'));
        self::assertSame('2026-01-31', $filter->dateTo->format('Y-m-d'));
        self::assertSame([3, 5], $filter->hospitalIds);
        self::assertSame('red', $filter->urgency);
        self::assertSame(1, $filter->requiresResus);
        self::assertFalse($filter->includeIndicationRaw);
    }

    public function testIncludeIndicationRawFlagIsMapped(): void
    {
        $data = new OwnHospitalAllocationsExportFormData();
        $data->dateFrom = new \DateTimeImmutable('2026-01-01');
        $data->dateTo = new \DateTimeImmutable('2026-01-31');
        $data->includeIndicationRaw = true;

        $filter = $this->mapper->fromFormData($data);

        self::assertTrue($filter->includeIndicationRaw);
    }

    public function testMapsAssignmentOccasionAndDepartmentWasClosed(): void
    {
        $data = new OwnHospitalAllocationsExportFormData();
        $data->dateFrom = new \DateTimeImmutable('2026-01-01');
        $data->dateTo = new \DateTimeImmutable('2026-01-31');
        $data->assignment = 7;
        $data->occasion = '9';
        $data->departmentWasClosed = true;

        $filter = $this->mapper->fromFormData($data);

        self::assertSame(7, $filter->assignment);
        self::assertSame('9', $filter->occasion);
        self::assertSame(1, $filter->departmentWasClosed);
    }

    public function testMapsOptionalRelationSentinelsAndInfectiousAbsence(): void
    {
        $data = new OwnHospitalAllocationsExportFormData();
        $data->dateFrom = new \DateTimeImmutable('2026-01-01');
        $data->dateTo = new \DateTimeImmutable('2026-01-31');
        $data->secondaryTransport = 'none';
        $data->occasion = 'none';
        $data->secondaryIndication = 'none';
        $data->infection = 'none';

        $filter = $this->mapper->fromFormData($data);

        self::assertSame('none', $filter->secondaryTransport);
        self::assertSame('none', $filter->occasion);
        self::assertSame('none', $filter->secondaryIndication);
        self::assertSame(0, $filter->isInfectious);
        self::assertNull($filter->infection);
    }

    public function testMapsSpecificInfectionId(): void
    {
        $data = new OwnHospitalAllocationsExportFormData();
        $data->dateFrom = new \DateTimeImmutable('2026-01-01');
        $data->dateTo = new \DateTimeImmutable('2026-01-31');
        $data->infection = '12';

        $filter = $this->mapper->fromFormData($data);

        self::assertSame(1, $filter->isInfectious);
        self::assertSame(12, $filter->infection);
    }

    public function testMapsSecondaryIndicationSentinelAndId(): void
    {
        $none = new OwnHospitalAllocationsExportFormData();
        $none->dateFrom = new \DateTimeImmutable('2026-01-01');
        $none->dateTo = new \DateTimeImmutable('2026-01-31');
        $none->secondaryIndication = 'none';

        $id = new OwnHospitalAllocationsExportFormData();
        $id->dateFrom = new \DateTimeImmutable('2026-01-01');
        $id->dateTo = new \DateTimeImmutable('2026-01-31');
        $id->secondaryIndication = '21';

        self::assertSame('none', $this->mapper->fromFormData($none)->secondaryIndication);
        self::assertSame('21', $this->mapper->fromFormData($id)->secondaryIndication);
    }

    public function testEmptyHospitalSelectionMapsToNull(): void
    {
        $data = new OwnHospitalAllocationsExportFormData();
        $data->dateFrom = new \DateTimeImmutable('2026-01-01');
        $data->dateTo = new \DateTimeImmutable('2026-01-31');

        $filter = $this->mapper->fromFormData($data);

        self::assertNull($filter->hospitalIds);
    }

    public function testMissingDatesThrow(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->mapper->fromFormData(new OwnHospitalAllocationsExportFormData());
    }
}
