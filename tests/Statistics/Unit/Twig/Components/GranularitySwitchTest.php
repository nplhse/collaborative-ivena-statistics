<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Twig\Components;

use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Util\Period;
use App\Statistics\UI\Twig\Components\GranularitySwitch;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class GranularitySwitchTest extends TestCase
{
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->requestStack = new RequestStack();
    }

    private function makeComponent(
        Scope $scope,
        bool $showPeriod = false,
        string $variant = 'list',
        ?UrlGeneratorInterface $router = null,
    ): GranularitySwitch {
        $cmp = new GranularitySwitch(
            $this->requestStack,
            $router ?? $this->createStub(UrlGeneratorInterface::class),
        );
        $cmp->scope = $scope;
        $cmp->showPeriod = $showPeriod;
        $cmp->variant = $variant;

        return $cmp;
    }

    public function testOptionsReturnsAllGranularitiesInOrder(): void
    {
        $scope = new Scope('state', 'BY', Period::MONTH, '2025-11-08');
        $cmp = $this->makeComponent($scope);

        $options = $cmp->options();

        self::assertSame([Period::ALL, Period::YEAR, Period::QUARTER, Period::MONTH, Period::WEEK, Period::DAY], $options);
    }

    public function testIsActiveReflectsCurrentScopeGranularity(): void
    {
        $scope = new Scope('state', 'BY', Period::MONTH, '2025-11-08');
        $cmp = $this->makeComponent($scope);

        self::assertTrue($cmp->isActive(Period::MONTH));
        self::assertFalse($cmp->isActive(Period::YEAR));
    }

    public function testCurrentLabelUsesOptionLabelForCurrentGranularity(): void
    {
        $scope = new Scope('state', 'BY', Period::MONTH, '2025-11-08');
        $cmp = $this->makeComponent($scope);

        $label = $cmp->currentLabel();

        self::assertSame('Month', $label);
    }

    public function testOptionLabelForDayUsesMonthAnchorWhenScopeIsMonthAndShowPeriod(): void
    {
        $scope = new Scope('state', 'BY', Period::MONTH, '2025-11-08');
        $cmp = $this->makeComponent($scope, showPeriod: true);

        $label = $cmp->optionLabelFor(Period::DAY);

        self::assertSame('Day (Nov 1, 2025)', $label);
    }

    public function testUrlDerivesKeyForTargetsAndCallsRoute(): void
    {
        $scope = new Scope('state', 'BY', Period::MONTH, '2025-11-08');

        $request = new Request();
        $request->attributes->set('_route', 'dummy_route');
        $request->attributes->set('_route_params', [
            'type' => 'state',
            'id' => 'BY',
        ]);

        $request->query->replace([]);

        $this->requestStack->push($request);

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(fn (string $routeName, array $params): string => sprintf(
            '%s|%s|%s|%s',
            $params['type'],
            $params['id'],
            $params['gran'],
            $params['key'],
        ));

        $cmp = $this->makeComponent($scope, router: $router);

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
