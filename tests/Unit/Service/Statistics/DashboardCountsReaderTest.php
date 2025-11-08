<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Statistics;

use App\Model\DashboardPanelView;
use App\Model\Scope;
use App\Service\Statistics\DashboardCountsReader;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DashboardCountsReaderTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $db;

    protected function setUp(): void
    {
        // Arrange (shared): mock DBAL connection
        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        $this->db = $db;
    }

    public function testReadReturnsNullWhenNoRowFound(): void
    {
        // Arrange
        $scope = new Scope('state', 'BY', 'month', '2025-11-01');

        $this->db
            ->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::callback(function (string $sql): bool {
                    // Sanity check on SQL without being brittle
                    self::assertStringContainsString('FROM agg_allocations_counts', $sql);
                    self::assertStringContainsString('WHERE scope_type = :t', $sql);
                    self::assertStringContainsString('AND scope_id = :i', $sql);
                    self::assertStringContainsString('AND period_gran = :g', $sql);
                    self::assertStringContainsString('AND period_key = :k', $sql);

                    return true;
                }),
                ['t' => 'state', 'i' => 'BY', 'g' => 'month', 'k' => '2025-11-01']
            )
            ->willReturn(false);

        $sut = new DashboardCountsReader($this->db);

        // Act
        $result = $sut->read($scope);

        // Assert
        self::assertNull($result);
    }

    public function testReadMapsFieldsCastsTypesComputesPercentages(): void
    {
        // Arrange
        $scope = new Scope('hospital', '123', 'day', '2025-11-08');

        // total=10 -> easy percentages
        $row = [
            'computed_at' => '2025-11-08 10:30:00',
            'total' => '10',
            'gender_m' => '4',
            'gender_w' => 3,
            'gender_d' => 1,
            'gender_u' => 2,
            'urg_1' => 5,
            'urg_2' => 3,
            'urg_3' => 2,
            'cathlab_required' => 1,
            'resus_required' => '2',
            'is_cpr' => 1,
            'is_ventilated' => 2,
            'is_shock' => 1,
            'is_pregnant' => 0,
            'with_physician' => 6,
            'infectious' => 1,
        ];

        $this->db
            ->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn($row);

        $sut = new DashboardCountsReader($this->db);

        // Act
        $view = $sut->read($scope);

        // Assert
        self::assertInstanceOf(DashboardPanelView::class, $view);

        // Basic mapping/casts
        self::assertSame($scope, $view->scope);
        self::assertSame(10, $view->total);
        self::assertSame(4, $view->genderM);
        self::assertSame(3, $view->genderW);
        self::assertSame(1, $view->genderD);
        self::assertSame(2, $view->genderU);
        self::assertSame(5, $view->urg1);
        self::assertSame(3, $view->urg2);
        self::assertSame(2, $view->urg3);
        self::assertSame(1, $view->cathlabRequired);
        self::assertSame(2, $view->resusRequired);
        self::assertSame(1, $view->isCpr);
        self::assertSame(2, $view->isVentilated);
        self::assertSame(1, $view->isShock);
        self::assertSame(0, $view->isPregnant);
        self::assertSame(6, $view->withPhysician);
        self::assertSame(1, $view->infectious);

        // computedAt
        self::assertSame('2025-11-08 10:30:00', $view->computedAt->format('Y-m-d H:i:s'));

        // Percentages (rounded to 1 decimal)
        self::assertSame(40.0, $view->pctMale);
        self::assertSame(30.0, $view->pctFemale);
        self::assertSame(10.0, $view->pctDiverse);
        self::assertSame(20.0, $view->pctVentilated);
        self::assertSame(10.0, $view->pctCpr);
        self::assertSame(10.0, $view->pctShock);
        self::assertSame(0.0, $view->pctPregnant);
        self::assertSame(60.0, $view->pctWithPhysician);
        self::assertSame(10.0, $view->pctInfectious);
        self::assertSame(50.0, $view->pctUrg1);
        self::assertSame(30.0, $view->pctUrg2);
        self::assertSame(20.0, $view->pctUrg3);
        self::assertSame(10.0, $view->pctCathlabRequired);
        self::assertSame(20.0, $view->pctResusRequired);
    }

    public function testReadPercentagesAreZeroWhenTotalIsZero(): void
    {
        // Arrange
        $scope = new Scope('public', 'x', 'all', 'all');
        $row = [
            'computed_at' => '2025-01-01 00:00:00',
            'total' => 0,
            'gender_m' => 0,
            'gender_w' => 0,
            'gender_d' => 0,
            'gender_u' => 0,
            'urg_1' => 0,
            'urg_2' => 0,
            'urg_3' => 0,
            'cathlab_required' => 0,
            'resus_required' => 0,
            'is_cpr' => 0,
            'is_ventilated' => 0,
            'is_shock' => 0,
            'is_pregnant' => 0,
            'with_physician' => 0,
            'infectious' => 0,
        ];

        $this->db->method('fetchAssociative')->willReturn($row);
        $sut = new DashboardCountsReader($this->db);

        // Act
        $view = $sut->read($scope);

        // Assert
        self::assertInstanceOf(DashboardPanelView::class, $view);
        // all pct fields should be 0.0
        self::assertSame(0.0, $view->pctMale);
        self::assertSame(0.0, $view->pctFemale);
        self::assertSame(0.0, $view->pctDiverse);
        self::assertSame(0.0, $view->pctVentilated);
        self::assertSame(0.0, $view->pctCpr);
        self::assertSame(0.0, $view->pctShock);
        self::assertSame(0.0, $view->pctPregnant);
        self::assertSame(0.0, $view->pctWithPhysician);
        self::assertSame(0.0, $view->pctInfectious);
        self::assertSame(0.0, $view->pctUrg1);
        self::assertSame(0.0, $view->pctUrg2);
        self::assertSame(0.0, $view->pctUrg3);
        self::assertSame(0.0, $view->pctCathlabRequired);
        self::assertSame(0.0, $view->pctResusRequired);
    }

    public function testReadThrowsOnInvalidComputedAt(): void
    {
        // Arrange
        $scope = new Scope('hospital', '123', 'day', '2025-11-08');
        $row = [
            'computed_at' => 'not-a-date',
            'total' => 1,
            'gender_m' => 1,
            'gender_w' => 0,
            'gender_d' => 0,
            'gender_u' => 0,
            'urg_1' => 1,
            'urg_2' => 0,
            'urg_3' => 0,
            'cathlab_required' => 0,
            'resus_required' => 0,
            'is_cpr' => 0,
            'is_ventilated' => 0,
            'is_shock' => 0,
            'is_pregnant' => 0,
            'with_physician' => 0,
            'infectious' => 0,
        ];

        $this->db->method('fetchAssociative')->willReturn($row);
        $sut = new DashboardCountsReader($this->db);

        // Act & Assert
        $this->expectException(\Exception::class);
        $sut->read($scope);
    }
}
