<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerAssistantGoal;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerQueryKeys;
use App\Statistics\AnalysisExplorer\UI\Http\ExplorerAssistantQueryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ExplorerAssistantQueryFactoryTest extends TestCase
{
    private ExplorerAssistantQueryFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        $this->factory = new ExplorerAssistantQueryFactory();
    }

    public function testBlankValuesStayEmptyWithoutMarkingTheQueryMalformed(): void
    {
        $query = $this->factory->fromRequest(Request::create('/', 'GET', [
            ExplorerQueryKeys::GUIDE => ' ',
            ExplorerQueryKeys::ROW => '',
            ExplorerQueryKeys::COLUMN => '',
            ExplorerQueryKeys::GRAIN => '',
            ExplorerQueryKeys::COLUMN_GRAIN => '',
            ExplorerQueryKeys::METRIC => '',
            ExplorerQueryKeys::DATA_SOURCE => '',
            'department' => ' ',
            'age_group' => '',
            'resus' => ' ',
            'cpr' => '',
        ]));

        self::assertFalse($query->malformed);
        self::assertNull($query->goal);
        self::assertNull($query->row);
        self::assertNull($query->column);
        self::assertNull($query->grain);
        self::assertNull($query->columnGrain);
        self::assertSame(AnalysisMetricKey::AllocationCount, $query->metric);
        self::assertSame(AnalysisDataSourceKey::Allocations, $query->dataSource);
        self::assertNull($query->filters->departmentId);
        self::assertNull($query->filters->ageGroup);
        self::assertNull($query->filters->resus);
        self::assertNull($query->filters->cpr);
    }

    public function testUnknownValuesMarkTheQueryMalformedAndFallBackToDefaults(): void
    {
        $query = $this->factory->fromRequest(Request::create('/', 'GET', [
            ExplorerQueryKeys::GUIDE => 'nope',
            ExplorerQueryKeys::ROW => 'nope',
            ExplorerQueryKeys::METRIC => 'nope',
            ExplorerQueryKeys::DATA_SOURCE => 'nope',
            'speciality' => 'abc',
            'ventilation' => 'yes',
        ]));

        self::assertTrue($query->malformed);
        self::assertNull($query->goal);
        self::assertNull($query->row);
        self::assertSame(AnalysisMetricKey::AllocationCount, $query->metric);
        self::assertSame(AnalysisDataSourceKey::Allocations, $query->dataSource);
        self::assertNull($query->filters->specialityId);
        self::assertNull($query->filters->ventilation);
    }

    public function testCompleteQueryKeepsGuideAxesGrainsAndFilters(): void
    {
        $query = $this->factory->fromRequest(Request::create('/', 'GET', [
            ExplorerQueryKeys::GUIDE => ExplorerAssistantGoal::Matrix->value,
            ExplorerQueryKeys::ROW => AnalysisDimensionKey::Weekday->value,
            ExplorerQueryKeys::COLUMN => AnalysisDimensionKey::Hour->value,
            ExplorerQueryKeys::GRAIN => AnalysisDimensionGrain::Year->value,
            ExplorerQueryKeys::COLUMN_GRAIN => AnalysisDimensionGrain::Month->value,
            ExplorerQueryKeys::METRIC => AnalysisMetricKey::AllocationCount->value,
            ExplorerQueryKeys::DATA_SOURCE => AnalysisDataSourceKey::Hospitals->value,
            'department' => '4',
            'speciality' => '5',
            'urgency' => '1',
            'transport_type' => '2',
            'gender' => '2',
            'age_group' => ' under_18 ',
            'resus' => '1',
            'cpr' => '0',
            'ventilation' => '1',
            'assignment' => '3',
            'indication' => '6',
            'secondary_indication' => '7',
            'indication_group' => '8',
        ]));

        self::assertFalse($query->malformed);
        self::assertSame(ExplorerAssistantGoal::Matrix, $query->goal);
        self::assertSame(AnalysisDimensionKey::Weekday, $query->row);
        self::assertSame(AnalysisDimensionKey::Hour, $query->column);
        self::assertSame(AnalysisDimensionGrain::Year, $query->grain);
        self::assertSame(AnalysisDimensionGrain::Month, $query->columnGrain);
        self::assertSame(AnalysisMetricKey::AllocationCount, $query->metric);
        self::assertSame(AnalysisDataSourceKey::Hospitals, $query->dataSource);
        self::assertSame(4, $query->filters->departmentId);
        self::assertSame(5, $query->filters->specialityId);
        self::assertSame(1, $query->filters->urgency);
        self::assertSame(2, $query->filters->transportType);
        self::assertSame(2, $query->filters->gender);
        self::assertSame('under_18', $query->filters->ageGroup);
        self::assertTrue($query->filters->resus);
        self::assertFalse($query->filters->cpr);
        self::assertTrue($query->filters->ventilation);
        self::assertSame(3, $query->filters->assignmentId);
        self::assertSame(6, $query->filters->indicationId);
        self::assertSame(7, $query->filters->secondaryIndicationId);
        self::assertSame(8, $query->filters->indicationGroupId);
    }
}
