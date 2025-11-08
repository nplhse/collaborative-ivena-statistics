<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components;

use App\Model\Scope;
use App\Service\ScopeRoute;
use App\Service\Statistics\Util\Period;
use App\Twig\Components\GranularitySwitch;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GranularitySwitchTest extends TestCase
{
    /** @var ScopeRoute&MockObject */
    private ScopeRoute $route;

    protected function setUp(): void
    {
        /** @var ScopeRoute&MockObject $route */
        $route = $this->createMock(ScopeRoute::class);
        $this->route = $route;
    }

    private function makeComponent(Scope $scope, bool $showPeriod = false, string $variant = 'list'): GranularitySwitch
    {
        $cmp = new GranularitySwitch($this->route);
        $cmp->scope = $scope;
        $cmp->showPeriod = $showPeriod;
        $cmp->variant = $variant;

        return $cmp;
    }

    public function testOptionsReturnsAllGranularitiesInOrder(): void
    {
        // Arrange
        $scope = new Scope('state', 'BY', Period::MONTH, '2025-11-08');
        $cmp = $this->makeComponent($scope);

        // Act
        $options = $cmp->options();

        // Assert
        self::assertSame([Period::ALL, Period::YEAR, Period::QUARTER, Period::MONTH, Period::WEEK, Period::DAY], $options);
    }

    public function testIsActiveReflectsCurrentScopeGranularity(): void
    {
        // Arrange
        $scope = new Scope('state', 'BY', Period::MONTH, '2025-11-08');
        $cmp = $this->makeComponent($scope);

        // Act & Assert
        self::assertTrue($cmp->isActive(Period::MONTH));
        self::assertFalse($cmp->isActive(Period::YEAR));
    }

    public function testCurrentLabelUsesOptionLabelForCurrentGranularity(): void
    {
        // Arrange
        $scope = new Scope('state', 'BY', Period::MONTH, '2025-11-08');
        $cmp = $this->makeComponent($scope);

        // Act
        $label = $cmp->currentLabel();

        // Assert
        self::assertSame('Month', $label);
    }

    public function testOptionLabelForDayUsesMonthAnchorWhenScopeIsMonthAndShowPeriod(): void
    {
        // Arrange: Scope GRANULARITY = MONTH, periodKey = 2025-11-08 -> Day target anchored to startOfMonth => 2025-11-01
        $scope = new Scope('state', 'BY', Period::MONTH, '2025-11-08');
        $cmp = $this->makeComponent($scope, showPeriod: true);

        // Act
        $label = $cmp->optionLabelFor(Period::DAY);

        // Assert (Möglichkeit A)
        self::assertSame('Day (Nov 1, 2025)', $label);
    }

    public function testUrlDerivesKeyForTargetsAndCallsRoute(): void
    {
        // Arrange
        // Scope GRANULARITY = MONTH, periodKey = 2025-11-08
        // Erwartetes Verhalten (Möglichkeit A):
        //  - ALL:          key = Period::ALL_ANCHOR_DATE
        //  - YEAR:         key = 2025-01-01 (format(Y-m-01))
        //  - QUARTER:      key = 2025-10-01 (Q4 start) (format(Y-m-01))
        //  - MONTH:        key = 2025-11-01
        //  - WEEK:         anchor = startOfWeek( startOfMonth(2025-11-08) = 2025-11-01 ) = 2025-10-27
        //  - DAY:          anchor = startOfMonth(2025-11-08) = 2025-11-01
        $scope = new Scope('state', 'BY', Period::MONTH, '2025-11-08');
        $cmp = $this->makeComponent($scope);

        // we let the mock return a simple join to assert the derived key & granularity
        $this->route
            ->method('toPath')
            ->willReturnCallback(function (string $t, string $i, string $g, string $k): string {
                return sprintf('%s|%s|%s|%s', $t, $i, $g, $k);
            });

        // Act
        $uAll = $cmp->url(Period::ALL);
        $uYear = $cmp->url(Period::YEAR);
        $uQuarter = $cmp->url(Period::QUARTER);
        $uMonth = $cmp->url(Period::MONTH);
        $uWeek = $cmp->url(Period::WEEK);
        $uDay = $cmp->url(Period::DAY);

        // Assert (Möglichkeit A: Woche beginnt bei der Woche, die den 1. des Monats enthält)
        self::assertSame('state|BY|all|'.Period::ALL_ANCHOR_DATE, $uAll);
        self::assertSame('state|BY|year|2025-01-01', $uYear);
        self::assertSame('state|BY|quarter|2025-10-01', $uQuarter);
        self::assertSame('state|BY|month|2025-11-01', $uMonth);
        self::assertSame('state|BY|week|2025-10-27', $uWeek);
        self::assertSame('state|BY|day|2025-11-01', $uDay);
    }
}
