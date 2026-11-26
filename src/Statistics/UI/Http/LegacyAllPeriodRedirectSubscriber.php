<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http;

use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * TRANSITION REDIRECT (#1017) — remove in v0.4-beta.
 *
 * Until v0.3 the statistics pages used `period=all` (and `comparison_period=all`) for the rolling
 * 12-month window. That window is now `last_12_months`. Old bookmarks, e-mail links and shared URLs
 * are redirected here (302, not cached) so they keep showing the same numbers.
 *
 * Removing it in v0.4-beta: delete this class and its test, then give
 * {@see StatisticsFilterPeriod::AllTime} the value `all`, so `period=all` means the whole history.
 *
 * Runs after routing (priority 32) so only statistics controllers are affected; other contexts
 * (e.g. the admin dashboard) use their own `period` values.
 *
 * @psalm-suppress UnusedClass
 */
final readonly class LegacyAllPeriodRedirectSubscriber
{
    public const string LEGACY_ALL = 'all';

    private const string STATISTICS_CONTROLLER_PREFIX = 'App\\Statistics\\';

    /** @var list<string> */
    private const array PERIOD_KEYS = [
        StatisticsQueryKeys::PERIOD,
        StatisticsQueryKeys::COMPARISON_PERIOD,
    ];

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!\in_array($request->getMethod(), [Request::METHOD_GET, Request::METHOD_HEAD], true)) {
            return;
        }

        $controller = $request->attributes->get('_controller');
        if (!\is_string($controller) || !str_starts_with($controller, self::STATISTICS_CONTROLLER_PREFIX)) {
            return;
        }

        $query = $request->query->all();
        $changed = false;
        foreach (self::PERIOD_KEYS as $key) {
            if (self::LEGACY_ALL === ($query[$key] ?? null)) {
                $query[$key] = StatisticsFilterPeriod::Last12Months->value;
                $changed = true;
            }
        }

        if (!$changed) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $request->getBaseUrl().$request->getPathInfo().'?'.http_build_query($query),
            RedirectResponse::HTTP_FOUND,
        ));
    }
}
