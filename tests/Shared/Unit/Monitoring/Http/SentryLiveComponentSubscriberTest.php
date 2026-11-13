<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Monitoring\Http;

use App\Shared\Infrastructure\Monitoring\Http\SentryLiveComponentSubscriber;
use PHPUnit\Framework\TestCase;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SentryLiveComponentSubscriberTest extends TestCase
{
    public function testNamesTheTransactionAndTagsComponentAndAction(): void
    {
        [$transaction, $tags] = $this->dispatch($this->liveRequest('POST', 'AnalysisExplorerShell', 'run'));

        self::assertSame('POST ux_live_component AnalysisExplorerShell::run', $transaction->getName());
        self::assertSame('AnalysisExplorerShell', $tags['live_component'] ?? null);
        self::assertSame('run', $tags['live_action'] ?? null);
    }

    public function testTheDefaultActionIsCalledRender(): void
    {
        $request = $this->liveRequest('GET', 'AnalysisExplorerShell', 'get');
        $request->attributes->set('_component_default_action', true);

        [$transaction, $tags] = $this->dispatch($request);

        self::assertSame('GET ux_live_component AnalysisExplorerShell::render', $transaction->getName());
        self::assertSame('render', $tags['live_action'] ?? null);
    }

    public function testOtherRoutesKeepTheirName(): void
    {
        $request = Request::create('/statistics/explorer');
        $request->attributes->set('_route', 'app_statistics_explorer');

        [$transaction, $tags] = $this->dispatch($request);

        self::assertSame('GET app_statistics_explorer', $transaction->getName());
        self::assertArrayNotHasKey('live_component', $tags);
    }

    private function liveRequest(string $method, string $component, string $action): Request
    {
        $request = Request::create(\sprintf('/_components/%s/%s', $component, $action), $method);
        $request->attributes->set('_route', SentryLiveComponentSubscriber::ROUTE);
        $request->attributes->set('_live_component', $component);
        $request->attributes->set('_component_name', $component);
        $request->attributes->set('_live_action', $action);

        return $request;
    }

    /**
     * @return array{Transaction, array<string, string>}
     */
    private function dispatch(Request $request): array
    {
        $hub = new Hub(ClientBuilder::create()->getClient());
        $transaction = new Transaction(TransactionContext::make()->setName(\sprintf('%s %s', $request->getMethod(), (string) $request->attributes->get('_route'))), $hub);
        $hub->setSpan($transaction);

        new SentryLiveComponentSubscriber($hub)->onKernelController(new ControllerEvent(
            $this->createStub(HttpKernelInterface::class),
            static fn (): null => null,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        ));

        $event = Event::createEvent();
        $hub->configureScope(static function (Scope $scope) use (&$event): void {
            $event = $scope->applyToEvent($event) ?? $event;
        });

        return [$transaction, $event->getTags()];
    }
}
