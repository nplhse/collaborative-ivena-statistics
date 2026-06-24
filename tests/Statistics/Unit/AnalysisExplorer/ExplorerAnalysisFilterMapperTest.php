<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisFilterMapper;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator;
use PHPUnit\Framework\TestCase;

final class ExplorerAnalysisFilterMapperTest extends TestCase
{
    private ExplorerAnalysisFilterMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ExplorerAnalysisFilterMapper();
    }

    public function testFromFormDataMapsAllSupportedFilters(): void
    {
        $filters = $this->mapper->fromFormData(new ExplorerEditFormData(
            filterDepartmentIds: [10, 20],
            filterSpecialityIds: [3],
            filterUrgency: 1,
            filterTransportType: 2,
            filterGender: 1,
            filterAgeGroup: 'under_18',
            filterResus: true,
            filterCpr: false,
            filterVentilation: true,
            filterAssignmentId: 7,
        ));

        self::assertCount(10, $filters);
        self::assertSame('department', $filters[0]->dimensionKey);
        self::assertSame(AnalysisFilterOperator::In, $filters[0]->operator);
        self::assertSame([10, 20], $filters[0]->value);
        self::assertSame('urgency', $filters[2]->dimensionKey);
        self::assertSame(1, $filters[2]->value);
        self::assertSame('resus', $filters[6]->dimensionKey);
        self::assertSame(1, $filters[6]->value);
        self::assertSame('cpr', $filters[7]->dimensionKey);
        self::assertSame(0, $filters[7]->value);
    }

    public function testFromFormDataIgnoresEmptyValues(): void
    {
        self::assertSame([], $this->mapper->fromFormData(new ExplorerEditFormData()));
    }

    public function testApplyToFormDataRoundTripsFilters(): void
    {
        $original = new ExplorerEditFormData(
            filterDepartmentIds: [5],
            filterUrgency: 2,
            filterAgeGroup: 'over_80',
            filterResus: false,
        );

        $filters = $this->mapper->fromFormData($original);
        $restored = $this->mapper->applyToFormData(new ExplorerEditFormData(), $filters);

        self::assertSame([5], $restored->filterDepartmentIds);
        self::assertSame(2, $restored->filterUrgency);
        self::assertSame('over_80', $restored->filterAgeGroup);
        self::assertFalse($restored->filterResus);
        self::assertNull($restored->filterCpr);
    }

    public function testToStateArrayAndFromStateArrayRoundTrip(): void
    {
        $filters = [
            new AnalysisFilter('gender', AnalysisFilterOperator::Equals, 2),
            new AnalysisFilter('department', AnalysisFilterOperator::In, [1, 2]),
        ];

        $state = $this->mapper->toStateArray($filters);
        $restored = $this->mapper->fromStateArray($state);

        self::assertCount(2, $restored);
        self::assertSame('gender', $restored[0]->dimensionKey);
        self::assertSame(2, $restored[0]->value);
        self::assertSame([1, 2], $restored[1]->value);
    }

    public function testFromStateArraySkipsInvalidRowsAndUnknownDimensions(): void
    {
        $filters = $this->mapper->fromStateArray([
            'not-an-array',
            ['dimensionKey' => 'evil', 'operator' => 'equals', 'value' => 1],
            ['dimensionKey' => 'gender', 'operator' => 'equals', 'value' => 1],
            ['dimensionKey' => 'urgency', 'operator' => 'unknown', 'value' => 2],
        ]);

        self::assertCount(2, $filters);
        self::assertSame('gender', $filters[0]->dimensionKey);
        self::assertSame('urgency', $filters[1]->dimensionKey);
        self::assertSame(AnalysisFilterOperator::Equals, $filters[1]->operator);
    }

    public function testApplyToFormDataCoercesStringAndListValues(): void
    {
        $restored = $this->mapper->applyToFormData(new ExplorerEditFormData(), [
            new AnalysisFilter('department', AnalysisFilterOperator::In, ['10', 20]),
            new AnalysisFilter('urgency', AnalysisFilterOperator::Equals, '3'),
            new AnalysisFilter('age_group', AnalysisFilterOperator::Equals, '30_39'),
            new AnalysisFilter('ventilation', AnalysisFilterOperator::Equals, 1),
            new AnalysisFilter('assignment', AnalysisFilterOperator::Equals, 'invalid'),
            new AnalysisFilter('unknown_axis', AnalysisFilterOperator::Equals, 1),
        ]);

        self::assertSame([10, 20], $restored->filterDepartmentIds);
        self::assertSame(3, $restored->filterUrgency);
        self::assertSame('30_39', $restored->filterAgeGroup);
        self::assertTrue($restored->filterVentilation);
        self::assertNull($restored->filterAssignmentId);
    }
}
