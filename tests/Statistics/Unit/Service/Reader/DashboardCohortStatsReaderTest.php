<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Service\Reader;

use App\Statistics\Domain\Model\CohortRate;
use App\Statistics\Domain\Model\CohortStatsView;
use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Reader\DashboardCohortStatsReader;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DashboardCohortStatsReaderTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $db;

    protected function setUp(): void
    {
        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        $this->db = $db;
    }

    public function testReadReturnsNullWhenNoRow(): void
    {
        // Arrange
        $this->db->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::stringContains('FROM agg_allocations_cohort_stats'),
                ['t' => 'hospital_location', 'i' => 'north', 'g' => 'year', 'k' => '2025-01-01']
            )
            ->willReturn(false);

        $sut = new DashboardCohortStatsReader($this->db);
        $scope = new Scope('hospital_location', 'north', 'year', '2025-01-01');

        // Act
        $result = $sut->read($scope);

        // Assert
        self::assertNull($result);
    }

    public function testReadParsesRowAndBuildsRates(): void
    {
        // Arrange
        $ratesJson = json_encode([
            'total' => ['mean' => '12.5', 'sd' => 3, 'var' => '9.0'],
            'is_cpr' => ['mean' => null, 'sd' => '1.2', 'var' => 'NaN'], // -> var becomes null
        ], JSON_THROW_ON_ERROR);

        $row = [
            'n' => '15',
            'mean_total' => '11.7',
            'rates' => $ratesJson,
            'computed_at' => '2025-11-08 09:30:00',
        ];

        $this->db->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::stringContains('FROM agg_allocations_cohort_stats'),
                ['t' => 'hospital_cohort', 'i' => 'basic_urban', 'g' => 'month', 'k' => '2025-11-01']
            )
            ->willReturn($row);

        $sut = new DashboardCohortStatsReader($this->db);
        $scope = new Scope('hospital_cohort', 'basic_urban', 'month', '2025-11-01');

        // Act
        $view = $sut->read($scope);

        // Assert
        self::assertInstanceOf(CohortStatsView::class, $view);
        self::assertSame(15, $view->n);
        self::assertSame(11.7, $view->meanTotal);
        self::assertSame('2025-11-08 09:30:00', $view->computedAt->format('Y-m-d H:i:s'));

        self::assertArrayHasKey('total', $view->rates);
        self::assertArrayHasKey('is_cpr', $view->rates);
        self::assertInstanceOf(CohortRate::class, $view->rates['total']);

        self::assertSame(12.5, $view->rates['total']->mean);
        self::assertSame(3.0, $view->rates['total']->sd);
        self::assertSame(9.0, $view->rates['total']->var);

        self::assertNull($view->rates['is_cpr']->mean);         // null stays null
        self::assertSame(1.2, $view->rates['is_cpr']->sd);      // numeric string -> float
        self::assertNull($view->rates['is_cpr']->var);          // "NaN" -> not numeric -> null
    }

    public function testReadThrowsOnInvalidRatesJson(): void
    {
        // Arrange
        $row = [
            'n' => 1,
            'mean_total' => 1.0,
            'rates' => '{not-json}', // will cause JsonException
            'computed_at' => '2025-01-01 00:00:00',
        ];

        $this->db->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn($row);

        $sut = new DashboardCohortStatsReader($this->db);
        $scope = new Scope('hospital', '123', 'year', '2025-01-01');

        // Act & Assert
        $this->expectException(\JsonException::class);
        $sut->read($scope);
    }
}
