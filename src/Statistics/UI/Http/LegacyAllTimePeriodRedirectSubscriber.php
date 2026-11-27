<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http;

use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\StatisticsQueryKeys;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * TRANSITION REDIRECT (#1035) — remove in v1.0.
 *
 * Until v0.3 the whole history was `period=all_time` (and `comparison_period=all_time`). Since v0.4-beta it is
 * `period=all` ({@see StatisticsFilterPeriod::AllTime}). Old bookmarks, e-mail links and links in CMS content
 * are redirected here (301) so they keep showing the same numbers.
 *
 * Removing it in v1.0: delete this class and its tests; `all_time` is then an unknown period like any other.
 *
 * Runs after routing so only statistics pages are affected: controllers of the Statistics context and pages
 * under `/statistics/` that plugins add. Other contexts (e.g. the admin dashboard) use their own `period` values.
 *
 * @psalm-suppress UnusedClass
 */
final readonly class LegacyAllTimePeriodRedirectSubscriber
{
    public const string LEGACY_ALL_TIME = 'all_time';

    private const string STATISTICS_CONTROLLER_PREFIX = 'App\\Statistics\\';

    private const string STATISTICS_PATH_PREFIX = '/statistics/';

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

        if (!$this->isStatisticsPage($request)) {
            return;
        }

        $query = $request->query->all();
        $changed = false;
        foreach (self::PERIOD_KEYS as $key) {
            if (self::LEGACY_ALL_TIME === ($query[$key] ?? null)) {
                $query[$key] = StatisticsFilterPeriod::AllTime->value;
                $changed = true;
            }
        }

        if (!$changed) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $request->getBaseUrl().$request->getPathInfo().'?'.http_build_query($query),
            RedirectResponse::HTTP_MOVED_PERMANENTLY,
        ));
    }

    private function isStatisticsPage(Request $request): bool
    {
        $controller = $request->attributes->get('_controller');
        if (\is_string($controller) && str_starts_with($controller, self::STATISTICS_CONTROLLER_PREFIX)) {
            return true;
        }

        return str_starts_with($request->getPathInfo(), self::STATISTICS_PATH_PREFIX);
    }
}
