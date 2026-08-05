<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\Http;

use App\Analytics\Application\RequestTracking\AnalyticsUserKeyGenerator;
use App\Analytics\Application\RequestTracking\FeatureAreaResolver;
use App\Analytics\Application\RequestTracking\QueryParameterNameExtractor;
use App\Analytics\Application\RequestTracking\RequestAnalyticsRecorder;
use App\Analytics\Application\RequestTracking\UserAgentNormalizer;
use App\Analytics\Infrastructure\Doctrine\AnalyticsQueryCounter;
use App\Shared\Domain\Entity\CookieConsent;
use App\Shared\Infrastructure\Consent\CookieConsentService;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @psalm-suppress UnusedClass Registered via #[AsEventListener].
 */
final readonly class AnalyticsRequestSubscriber
{
    private const string ATTR_STARTED_AT = '_analytics_started_at';

    private const string ATTR_TRACK = '_analytics_track';

    /** @var list<string> */
    private const array SKIP_ROUTE_PREFIXES = [
        '_wdt',
        '_profiler',
        '_fragment',
        'app_cookie_',
    ];

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private AnalyticsQueryCounter $queryCounter,
        private AnalyticsCookieManager $cookieManager,
        private CookieConsentService $cookieConsentService,
        private FeatureAreaResolver $featureAreaResolver,
        private UserAgentNormalizer $userAgentNormalizer,
        private QueryParameterNameExtractor $queryParameterNameExtractor,
        private AnalyticsUserKeyGenerator $analyticsUserKeyGenerator,
        private RequestAnalyticsRecorder $recorder,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($this->shouldSkip($request)) {
            $request->attributes->set(self::ATTR_TRACK, false);

            return;
        }

        $request->attributes->set(self::ATTR_TRACK, true);
        $request->attributes->set(self::ATTR_STARTED_AT, hrtime(true));
        $this->queryCounter->reset();
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -64)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (true !== $request->attributes->get(self::ATTR_TRACK)) {
            return;
        }

        $consent = $this->resolveConsent($request);
        $this->cookieManager->applyCookies(
            $request,
            $event->getResponse(),
            $this->cookieConsentService->hasAnalyticsConsent($consent),
        );
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onKernelTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        if (true !== $request->attributes->get(self::ATTR_TRACK)) {
            return;
        }

        try {
            $this->recordRequest($request, $event->getResponse()->getStatusCode());
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to record analytics request.', [
                'exception' => $e,
            ]);
        }
    }

    private function recordRequest(Request $request, int $statusCode): void
    {
        $startedAt = $request->attributes->get(self::ATTR_STARTED_AT);
        $durationMs = 0;
        if (\is_int($startedAt) || \is_float($startedAt)) {
            $elapsedNs = (float) hrtime(true) - (float) $startedAt;
            $durationMs = (int) round($elapsedNs / 1_000_000.0);
        }

        $dbQueryCount = $this->queryCounter->getCount();
        $dbTimeMs = $this->queryCounter->getTimeMs();
        $this->queryCounter->disable();

        $consent = $this->resolveConsent($request);
        $analyticsConsent = $this->cookieConsentService->hasAnalyticsConsent($consent);
        $keys = $this->cookieManager->resolveKeys($request, $analyticsConsent);

        $securityUser = $this->security->getUser();
        $appUser = $securityUser instanceof User ? $securityUser : null;
        $isAuthenticated = $appUser instanceof User;
        $userRole = $isAuthenticated ? $this->resolvePrimaryRole($appUser) : null;
        $analyticsUserKey = null;
        if ($analyticsConsent && $appUser instanceof User) {
            $userId = $appUser->getId();
            if (null !== $userId) {
                $analyticsUserKey = $this->analyticsUserKeyGenerator->generate($userId);
            }
        }

        $routeName = $request->attributes->get('_route');
        $routeName = \is_string($routeName) ? $routeName : null;
        $ua = $this->userAgentNormalizer->normalize($request->headers->get('User-Agent'));

        $this->recorder->record(
            occurredAt: new \DateTimeImmutable(),
            routeName: $routeName,
            featureArea: $this->featureAreaResolver->resolve($routeName),
            httpStatus: $statusCode,
            durationMs: max(0, $durationMs),
            dbQueryCount: $dbQueryCount,
            dbTimeMs: $dbTimeMs,
            isAuthenticated: $isAuthenticated,
            userRole: $userRole,
            analyticsUserKey: $analyticsUserKey,
            visitorKey: $keys['visitorKey'],
            sessionKey: $keys['sessionKey'],
            browserFamily: $ua['browserFamily'],
            deviceType: $ua['deviceType'],
            queryParamNames: $this->queryParameterNameExtractor->extract($request->query->all()),
        );
    }

    private function resolveConsent(Request $request): CookieConsent
    {
        $user = $this->security->getUser();

        return $this->cookieConsentService->resolveForRequest(
            $request,
            $user instanceof User ? $user : null,
        );
    }

    private function resolvePrimaryRole(User $user): string
    {
        $roles = $user->getRoles();
        foreach ([UserRole::ADMIN, UserRole::PARTICIPANT, UserRole::USER] as $preferred) {
            if (\in_array($preferred, $roles, true)) {
                return $preferred;
            }
        }

        return $roles[0] ?? UserRole::USER;
    }

    private function shouldSkip(Request $request): bool
    {
        $route = $request->attributes->get('_route');
        if (\is_string($route)) {
            foreach (self::SKIP_ROUTE_PREFIXES as $prefix) {
                if (str_starts_with($route, $prefix)) {
                    return true;
                }
            }
        }

        $path = $request->getPathInfo();

        return str_starts_with($path, '/_wdt')
            || str_starts_with($path, '/_profiler')
            || str_starts_with($path, '/assets/')
            || str_starts_with($path, '/build/')
            || str_starts_with($path, '/bundles/');
    }
}
