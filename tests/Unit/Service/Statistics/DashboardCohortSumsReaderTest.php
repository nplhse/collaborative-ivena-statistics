<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Statistics;

use App\Model\CohortSumsView;
use App\Model\Scope;
use App\Service\Statistics\DashboardCohortSumsReader;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DashboardCohortSumsReaderTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $db;

    protected function setUp(): void
    {
        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        $this->db = $db;
    }

    public function testReturnsNullWhenNoRowFound(): void
    {
        // Arrange
        $scope = new Scope('hospital_size', 'small', 'month', '2025-11-01');

        $this->db
            ->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::callback(fn (string $sql): bool => str_contains($sql, 'agg_allocations_cohort_sums')
                ),
                ['t' => 'hospital_size', 'i' => 'small', 'g' => 'month', 'k' => '2025-11-01']
            )
            ->willReturn(false);

        $sut = new DashboardCohortSumsReader($this->db);

        // Act
        $result = $sut->read($scope);

        // Assert
        self::assertNull($result);
    }

    public function testMapsFieldsCorrectly(): void
    {
        // Arrange
        $scope = new Scope('hospital_tier', 'advanced', 'day', '2025-11-08');

        $row = [
            'computed_at' => '2025-11-08 12:00:00',
            'total' => '7',
            'gender_m' => '3',
            'gender_w' => 2,
            'gender_d' => 1,
            'gender_u' => 1,
            'urg_1' => 2,
            'urg_2' => 3,
            'urg_3' => 2,
            'cathlab_required' => 1,
            'resus_required' => 0,
            'is_cpr' => 1,
            'is_ventilated' => 0,
            'is_shock' => 1,
            'is_pregnant' => 0,
            'with_physician' => 4,
            'infectious' => 0,
        ];

        $this->db->method('fetchAssociative')->willReturn($row);

        $sut = new DashboardCohortSumsReader($this->db);

        // Act
        $view = $sut->read($scope);

        // Assert
        self::assertInstanceOf(CohortSumsView::class, $view);

        self::assertSame($scope, $view->scope);
        self::assertSame(7, $view->total);
        self::assertSame('2025-11-08 12:00:00', $view->computedAt->format('Y-m-d H:i:s'));

        self::assertSame(3, $view->genderM);
        self::assertSame(2, $view->genderW);
        self::assertSame(1, $view->genderD);
        self::assertSame(1, $view->genderU);

        self::assertSame(2, $view->urg1);
        self::assertSame(3, $view->urg2);
        self::assertSame(2, $view->urg3);

        self::assertSame(1, $view->cathlabRequired);
        self::assertSame(0, $view->resusRequired);
        self::assertSame(1, $view->isCpr);
        self::assertSame(0, $view->isVentilated);
        self::assertSame(1, $view->isShock);
        self::assertSame(0, $view->isPregnant);
        self::assertSame(4, $view->withPhysician);
        self::assertSame(0, $view->infectious);
    }

    public function testThrowsOnInvalidDatetime(): void
    {
        // Arrange
        $scope = new Scope('hospital_tier', 'adv', 'day', '2025-01-01');

        $row = [
            'computed_at' => 'not-a-date',
            'total' => 1,
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
        $sut = new DashboardCohortSumsReader($this->db);

        // Act & Assert
        $this->expectException(\Exception::class);
        $sut->read($scope);
    }
}
