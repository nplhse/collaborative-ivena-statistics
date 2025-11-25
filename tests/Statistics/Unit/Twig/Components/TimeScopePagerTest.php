<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Twig\Components;

use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Navigator\TimeScopeNavigator;
use App\Statistics\Infrastructure\Util\Period;
use App\Statistics\UI\Twig\Components\TimeScopePager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

final class TimeScopePagerTest extends TestCase
{
    /** @var RequestStack&MockObject */
    private $requestStack;

    /** @var RouterInterface&MockObject */
    private $router;

    /** @var TimeScopeNavigator&MockObject */
    private $navigator;

    protected function setUp(): void
    {
        /** @var RequestStack&MockObject $requestStack */
        $requestStack = $this->createMock(RequestStack::class);
        $this->requestStack = $requestStack;

        /** @var RouterInterface&MockObject $router */
        $router = $this->createMock(RouterInterface::class);
        $this->router = $router;

        /** @var TimeScopeNavigator&MockObject $navigator */
        $navigator = $this->createMock(TimeScopeNavigator::class);
        $this->navigator = $navigator;
    }

    private function makeComponent(Scope $scope): TimeScopePager
    {
        $cmp = new TimeScopePager($this->requestStack, $this->router, $this->navigator);
        $cmp->scope = $scope;

        return $cmp;
    }

    public function testInitDisablesPrevAndNextWhenNavigatorReturnsNoSides(): void
    {
        $scope = new Scope('state', 'BY', Period::MONTH, '2025-11-08');

        $this->navigator
            ->expects($this->once())
            ->method('calculate')
            ->with($scope)
            ->willReturn([]);

        $cmp = $this->makeComponent($scope);

        $cmp->init();

        self::assertTrue($cmp->prev['disabled']);
        self::assertSame('#', $cmp->prev['url']);
        self::assertSame('', $cmp->prev['label']);
        self::assertNull($cmp->prev['hint']);

        self::assertTrue($cmp->next['disabled']);
        self::assertSame('#', $cmp->next['url']);
        self::assertSame('', $cmp->next['label']);
        self::assertNull($cmp->next['hint']);
    }

    public function testInitBuildsEnabledPrevAndNextWithinBounds(): void
    {
        $scope = new Scope('state', 'BY', Period::MONTH, '2025-11-08');

        $today = new \DateTimeImmutable('today');
        $todayKey = $today->format('Y-m-d');

        $oneYearAgo = $today->modify('-1 year');
        $prevKey = $oneYearAgo->format('Y-m-d');

        $this->navigator
            ->expects($this->once())
            ->method('calculate')
            ->with($scope)
            ->willReturn([
                'prev' => [
                    'key' => $prevKey,
                    'label' => 'Prev label',
                    'hint' => 'Prev hint',
                ],
                'next' => [
                    'key' => $todayKey,
                    'label' => 'Next label',
                    'hint' => 'Next hint',
                ],
            ]);

        $request = new Request();
        $request->attributes->set('_route', 'dummy_route');
        $request->attributes->set('_route_params', ['type' => 'state', 'id' => 'BY']);
        $request->query->replace(['foo' => 'bar']);

        $this->requestStack
            ->method('getCurrentRequest')
            ->willReturn($request);

        $this->router
            ->method('generate')
            ->willReturnCallback(function (string $route, array $params): string {
                return sprintf('URL:%s', $params['key']);
            });

        $cmp = $this->makeComponent($scope);

        $cmp->init();

        // Prev
        self::assertFalse($cmp->prev['disabled']);
        self::assertSame('Prev label', $cmp->prev['label']);
        self::assertSame('Prev hint', $cmp->prev['hint']);
        self::assertSame('URL:'.$prevKey, $cmp->prev['url']);

        // Next
        self::assertFalse($cmp->next['disabled']);
        self::assertSame('Next label', $cmp->next['label']);
        self::assertSame('Next hint', $cmp->next['hint']);
        self::assertSame('URL:'.$todayKey, $cmp->next['url']);
    }

    public function testPrevDisabledWhenCandidateBeforeMinAndNextDisabledWhenCandidateAfterMax(): void
    {
        $scope = new Scope('state', 'BY', Period::MONTH, '2025-11-08');

        $tooEarly = '2000-01-01';
        $tooLate = '2999-01-01';

        $this->navigator
            ->expects($this->once())
            ->method('calculate')
            ->with($scope)
            ->willReturn([
                'prev' => [
                    'key' => $tooEarly,
                    'label' => 'Prev',
                ],
                'next' => [
                    'key' => $tooLate,
                    'label' => 'Next',
                ],
            ]);

        $this->router
            ->expects($this->never())
            ->method('generate');

        $this->requestStack
            ->method('getCurrentRequest')
            ->willReturn(null);

        $cmp = $this->makeComponent($scope);

        $cmp->init();

        self::assertTrue($cmp->prev['disabled']);
        self::assertSame('#', $cmp->prev['url']);
        self::assertSame('Prev', $cmp->prev['label']);

        self::assertTrue($cmp->next['disabled']);
        self::assertSame('#', $cmp->next['url']);
        self::assertSame('Next', $cmp->next['label']);
    }

    public function testBuildUrlReturnsHashWhenNoCurrentRequest(): void
    {
        $scope = new Scope('state', 'BY', Period::MONTH, '2025-11-08');

        $this->navigator
            ->expects($this->once())
            ->method('calculate')
            ->with($scope)
            ->willReturn([
                'prev' => [
                    'key' => '2025-10-01',
                    'label' => 'Prev',
                ],
            ]);

        $this->requestStack
            ->method('getCurrentRequest')
            ->willReturn(null);

        $this->router
            ->expects($this->never())
            ->method('generate');

        $cmp = $this->makeComponent($scope);

        $cmp->init();

        self::assertFalse($cmp->prev['disabled']);
        self::assertSame('#', $cmp->prev['url']);
    }

    public function testBuildUrlMergesRouteAndQueryParamsAndOverridesGranularityAndKey(): void
    {
        $scope = new Scope('state', 'BY', Period::WEEK, '2025-11-08');

        $this->navigator
            ->expects($this->once())
            ->method('calculate')
            ->with($scope)
            ->willReturn([
                'next' => [
                    'key' => '2025-11-15',
                    'label' => 'Next',
                ],
            ]);

        $request = new Request(['gran' => 'day', 'other' => 'keep']);
        $request->attributes->set('_route', 'stats_timegrid');
        $request->attributes->set('_route_params', ['type' => 'state', 'id' => 'BY']);

        $this->requestStack
            ->method('getCurrentRequest')
            ->willReturn($request);

        $this->router
            ->expects($this->once())
            ->method('generate')
            ->with(
                'stats_timegrid',
                self::callback(function (array $params) use ($scope): bool {
                    self::assertSame('state', $params['type']);
                    self::assertSame('BY', $params['id']);
                    self::assertSame('keep', $params['other']);
                    self::assertSame($scope->granularity, $params['gran']);
                    self::assertSame('2025-11-15', $params['key']);

                    return true;
                })
            )
            ->willReturn('/dummy/url');

        $cmp = $this->makeComponent($scope);

        $cmp->init();

        self::assertFalse($cmp->next['disabled']);
        self::assertSame('/dummy/url', $cmp->next['url']);
    }
}
