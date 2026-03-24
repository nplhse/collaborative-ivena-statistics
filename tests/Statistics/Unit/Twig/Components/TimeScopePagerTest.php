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
    /**
     * @param TimeScopeNavigator&MockObject $navigator
     */
    private function makePager(
        Scope $scope,
        TimeScopeNavigator $navigator,
        ?RequestStack $requestStack = null,
        ?RouterInterface $router = null,
    ): TimeScopePager {
        $pager = new TimeScopePager(
            $requestStack ?? $this->createStub(RequestStack::class),
            $router ?? $this->createStub(RouterInterface::class),
            $navigator,
        );
        $pager->scope = $scope;

        return $pager;
    }

    public function testInitDisablesPrevAndNextWhenNavigatorReturnsNoSides(): void
    {
        $scope = new Scope('state', 'BY', Period::MONTH, '2025-11-08');

        /** @var TimeScopeNavigator&MockObject $navigator */
        $navigator = $this->createMock(TimeScopeNavigator::class);
        $navigator
            ->expects($this->once())
            ->method('calculate')
            ->with($scope)
            ->willReturn([]);

        $cmp = $this->makePager($scope, $navigator);

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

        /** @var TimeScopeNavigator&MockObject $navigator */
        $navigator = $this->createMock(TimeScopeNavigator::class);
        $navigator
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

        $requestStack = $this->createStub(RequestStack::class);
        $requestStack->method('getCurrentRequest')->willReturn($request);

        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturnCallback(
            fn (string $route, array $params): string => sprintf('URL:%s', $params['key'])
        );

        $cmp = $this->makePager($scope, $navigator, $requestStack, $router);

        $cmp->init();

        self::assertFalse($cmp->prev['disabled']);
        self::assertSame('Prev label', $cmp->prev['label']);
        self::assertSame('Prev hint', $cmp->prev['hint']);
        self::assertSame('URL:'.$prevKey, $cmp->prev['url']);

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

        /** @var TimeScopeNavigator&MockObject $navigator */
        $navigator = $this->createMock(TimeScopeNavigator::class);
        $navigator
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

        /** @var RouterInterface&MockObject $router */
        $router = $this->createMock(RouterInterface::class);
        $router->expects($this->never())->method('generate');

        $requestStack = $this->createStub(RequestStack::class);
        $requestStack->method('getCurrentRequest')->willReturn(null);

        $cmp = $this->makePager($scope, $navigator, $requestStack, $router);

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

        /** @var TimeScopeNavigator&MockObject $navigator */
        $navigator = $this->createMock(TimeScopeNavigator::class);
        $navigator
            ->expects($this->once())
            ->method('calculate')
            ->with($scope)
            ->willReturn([
                'prev' => [
                    'key' => '2025-10-01',
                    'label' => 'Prev',
                ],
            ]);

        $requestStack = $this->createStub(RequestStack::class);
        $requestStack->method('getCurrentRequest')->willReturn(null);

        /** @var RouterInterface&MockObject $router */
        $router = $this->createMock(RouterInterface::class);
        $router->expects($this->never())->method('generate');

        $cmp = $this->makePager($scope, $navigator, $requestStack, $router);

        $cmp->init();

        self::assertFalse($cmp->prev['disabled']);
        self::assertSame('#', $cmp->prev['url']);
    }

    public function testBuildUrlMergesRouteAndQueryParamsAndOverridesGranularityAndKey(): void
    {
        $scope = new Scope('state', 'BY', Period::WEEK, '2025-11-08');

        /** @var TimeScopeNavigator&MockObject $navigator */
        $navigator = $this->createMock(TimeScopeNavigator::class);
        $navigator
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

        $requestStack = $this->createStub(RequestStack::class);
        $requestStack->method('getCurrentRequest')->willReturn($request);

        /** @var RouterInterface&MockObject $router */
        $router = $this->createMock(RouterInterface::class);
        $router
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

        $cmp = $this->makePager($scope, $navigator, $requestStack, $router);

        $cmp->init();

        self::assertFalse($cmp->next['disabled']);
        self::assertSame('/dummy/url', $cmp->next['url']);
    }
}
