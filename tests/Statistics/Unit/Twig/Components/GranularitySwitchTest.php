<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Twig\Components;

use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Util\Period;
use App\Statistics\UI\Twig\Components\GranularitySwitch;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class GranularitySwitchTest extends TestCase
{
    private RequestStack $requestStack;

    /** @var UrlGeneratorInterface&MockObject */
    private UrlGeneratorInterface $router;

    protected function setUp(): void
    {
        $this->requestStack = new RequestStack();

        /** @var UrlGeneratorInterface&MockObject $router */
        $router = $this->createMock(UrlGeneratorInterface::class);
        $this->router = $router;
    }

    private function makeComponent(Scope $scope, bool $showPeriod = false, string $variant = 'list'): GranularitySwitch
    {
        $cmp = new GranularitySwitch($this->requestStack, $this->router);
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
        $scope = new Scope('state', 'BY', Period::MONTH, '2025-11-08');
        $cmp = $this->makeComponent($scope);

        $request = new Request();
        $request->attributes->set('_route', 'dummy_route');
        $request->attributes->set('_route_params', [
            'type' => 'state',
            'id' => 'BY',
        ]);

        $request->query->replace([]);

        $this->requestStack->push($request);

        $this->router
            ->method('generate')
            ->willReturnCallback(function (string $routeName, array $params): string {
                return sprintf(
                    '%s|%s|%s|%s',
                    $params['type'],
                    $params['id'],
                    $params['gran'],
                    $params['key'],
                );
            });

        // Act
        $uAll = $cmp->url(Period::ALL);
        $uYear = $cmp->url(Period::YEAR);
        $uQuarter = $cmp->url(Period::QUARTER);
        $uMonth = $cmp->url(Period::MONTH);
        $uWeek = $cmp->url(Period::WEEK);
        $uDay = $cmp->url(Period::DAY);

        self::assertSame('state|BY|all|'.Period::ALL_ANCHOR_DATE, $uAll);
        self::assertSame('state|BY|year|2025-01-01', $uYear);
        self::assertSame('state|BY|quarter|2025-10-01', $uQuarter);
        self::assertSame('state|BY|month|2025-11-01', $uMonth);
        self::assertSame('state|BY|week|2025-10-27', $uWeek);
        self::assertSame('state|BY|day|2025-11-01', $uDay);
    }
}
