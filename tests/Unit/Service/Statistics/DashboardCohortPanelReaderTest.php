<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Statistics;

use App\Model\CohortStatsView;
use App\Model\CohortSumsView;
use App\Model\Scope;
use App\Service\Statistics\DashboardCohortPanelReader;
use App\Service\Statistics\DashboardCohortStatsReader;
use App\Service\Statistics\DashboardCohortSumsReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DashboardCohortPanelReaderTest extends TestCase
{
    /** @var DashboardCohortSumsReader&MockObject */
    private DashboardCohortSumsReader $sums;

    /** @var DashboardCohortStatsReader&MockObject */
    private DashboardCohortStatsReader $stats;

    protected function setUp(): void
    {
        // Arrange (shared): mock dependencies
        /** @var DashboardCohortSumsReader&MockObject $sums */
        $sums = $this->createMock(DashboardCohortSumsReader::class);
        /** @var DashboardCohortStatsReader&MockObject $stats */
        $stats = $this->createMock(DashboardCohortStatsReader::class);

        $this->sums = $sums;
        $this->stats = $stats;
    }

    public function testReadMergesResultsFromDependencies(): void
    {
        // Arrange
        $scope = new Scope('hospital_tier', 'advanced', 'year', '2025-01-01');

        // Use mocks for value objects to avoid constructor friction
        $sumsView = $this->createMock(CohortSumsView::class);
        $statsView = $this->createMock(CohortStatsView::class);

        $this->sums
            ->expects(self::once())
            ->method('read')
            ->with($scope)
            ->willReturn($sumsView);

        $this->stats
            ->expects(self::once())
            ->method('read')
            ->with($scope)
            ->willReturn($statsView);

        $sut = new DashboardCohortPanelReader($this->sums, $this->stats);

        // Act
        $result = $sut->read($scope);

        // Assert
        self::assertArrayHasKey('sums', $result);
        self::assertArrayHasKey('stats', $result);
        self::assertSame($sumsView, $result['sums'], 'Should return sums from sums reader');
        self::assertSame($statsView, $result['stats'], 'Should return stats from stats reader');
    }

    public function testReadAllowsNullResults(): void
    {
        // Arrange
        $scope = new Scope('hospital_size', 'large', 'month', '2025-11-01');

        $this->sums
            ->expects(self::once())
            ->method('read')
            ->with($scope)
            ->willReturn(null);

        $this->stats
            ->expects(self::once())
            ->method('read')
            ->with($scope)
            ->willReturn(null);

        $sut = new DashboardCohortPanelReader($this->sums, $this->stats);

        // Act
        $result = $sut->read($scope);

        // Assert
        self::assertNull($result['sums']);
        self::assertNull($result['stats']);
    }

    #[DataProvider('provideCohortScopeTypes')]
    public function testIsCohortScope(string $type, bool $expected): void
    {
        // Act
        $isCohort = DashboardCohortPanelReader::isCohortScope($type);

        // Assert
        self::assertSame($expected, $isCohort);
    }

    /**
     * @return iterable<array{0:string,1:bool}>
     */
    public static function provideCohortScopeTypes(): iterable
    {
        // true cases
        yield ['hospital_tier', true];
        yield ['hospital_size', true];
        yield ['hospital_location', true];
        yield ['hospital_cohort', true];

        // false cases
        yield ['hospital', false];
        yield ['state', false];
        yield ['dispatch_area', false];
        yield ['public', false];
        yield ['region', false];
    }
}
