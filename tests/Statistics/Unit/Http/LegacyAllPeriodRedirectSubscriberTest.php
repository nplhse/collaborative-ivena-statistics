<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Http;

use App\Statistics\UI\Http\LegacyAllPeriodRedirectSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * #1017 transition redirect (remove together with the subscriber in v0.4-beta).
 */
final class LegacyAllPeriodRedirectSubscriberTest extends TestCase
{
    private const string STATISTICS_CONTROLLER = 'App\\Statistics\\UI\\Http\\Controller\\TopListsController::index';

    public function testRedirectsPeriodAllToLast12MonthsAndKeepsOtherParameters(): void
    {
        $response = $this->dispatch(
            Request::create('/statistics/top-lists?scope=public&period=all&limit=10'),
            self::STATISTICS_CONTROLLER,
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/statistics/top-lists?scope=public&period=last_12_months&limit=10', $response->getTargetUrl());
    }

    public function testRedirectsComparisonPeriodAll(): void
    {
        $response = $this->dispatch(
            Request::create('/statistics/benchmarking?scope=public&period=year&year=2025&comparison_period=all'),
            'App\\Statistics\\Benchmarking\\UI\\Http\\Controller\\BenchmarkingController::index',
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(
            '/statistics/benchmarking?scope=public&period=year&year=2025&comparison_period=last_12_months',
            $response->getTargetUrl(),
        );
    }

    /**
     * @return iterable<string, array{Request, string}>
     */
    public static function untouchedRequests(): iterable
    {
        yield 'all_time stays' => [Request::create('/statistics/?period=all_time'), self::STATISTICS_CONTROLLER];
        yield 'last_12_months stays' => [Request::create('/statistics/?period=last_12_months'), self::STATISTICS_CONTROLLER];
        yield 'no period' => [Request::create('/statistics/?scope=public'), self::STATISTICS_CONTROLLER];
        yield 'POST is not redirected' => [Request::create('/statistics/?period=all', Request::METHOD_POST), self::STATISTICS_CONTROLLER];
        yield 'other context keeps its own period values' => [
            Request::create('/admin/?period=all'),
            'App\\Admin\\UI\\Http\\Controller\\DashboardController::index',
        ];
    }

    #[DataProvider('untouchedRequests')]
    public function testLeavesOtherRequestsAlone(Request $request, string $controller): void
    {
        self::assertNull($this->dispatch($request, $controller));
    }

    public function testIgnoresSubRequests(): void
    {
        $request = Request::create('/statistics/?period=all');
        $request->attributes->set('_controller', self::STATISTICS_CONTROLLER);
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::SUB_REQUEST);

        new LegacyAllPeriodRedirectSubscriber()->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    private function dispatch(Request $request, string $controller): ?Response
    {
        $request->attributes->set('_controller', $controller);
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        new LegacyAllPeriodRedirectSubscriber()->onKernelRequest($event);

        return $event->getResponse();
    }
}
