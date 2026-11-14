<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Http;

use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\Transaction;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * All Live Component requests share the route `ux_live_component`. This names the transaction
 * `<METHOD> ux_live_component <Component>::<action>` and tags `live_component` / `live_action`,
 * so an Explorer run can be told apart from a form re-render.
 *
 * Runs after the Live Component subscriber (kernel.controller, priority 10) has checked that the
 * component exists and the action is a LiveAction, so only registered names reach Sentry.
 *
 * @psalm-suppress UnusedClass
 */
final readonly class SentryLiveComponentSubscriber
{
    public const string ROUTE = 'ux_live_component';

    /** The default action re-renders the component; the bundle calls it `get`. */
    public const string RENDER_ACTION = 'render';

    public function __construct(
        private HubInterface $hub,
    ) {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER, priority: 0)]
    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (self::ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        $component = $request->attributes->get('_component_name');
        if (!\is_string($component) || '' === $component) {
            return;
        }

        $action = $request->attributes->get('_live_action');
        if ($request->attributes->getBoolean('_component_default_action') || !\is_string($action) || '' === $action) {
            $action = self::RENDER_ACTION;
        }

        $this->hub->configureScope(static function (Scope $scope) use ($component, $action): void {
            $scope->setTag('live_component', $component);
            $scope->setTag('live_action', $action);
        });

        $transaction = $this->hub->getTransaction();
        if ($transaction instanceof Transaction) {
            $transaction->setName(\sprintf('%s %s %s::%s', $request->getMethod(), self::ROUTE, $component, $action));
        }
    }
}
