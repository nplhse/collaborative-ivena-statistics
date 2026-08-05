<?php

declare(strict_types=1);

namespace App\Analytics\Application\UsageEvents;

use App\Analytics\Application\RequestTracking\AnalyticsUserKeyGenerator;
use App\Analytics\Infrastructure\Http\AnalyticsCookieManager;
use App\Shared\Infrastructure\Consent\CookieConsentService;
use App\Shared\Infrastructure\Repository\CookieConsentRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves consent + pseudonymous keys for usage events.
 *
 * @psalm-suppress UnusedClass Wired via services.yaml alias for UsageEventContextResolverInterface.
 */
final readonly class UsageEventContextResolver implements UsageEventContextResolverInterface
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private RequestStack $requestStack,
        private CookieConsentService $cookieConsentService,
        private CookieConsentRepository $cookieConsentRepository,
        private AnalyticsCookieManager $cookieManager,
        private AnalyticsUserKeyGenerator $userKeyGenerator,
        private Security $security,
    ) {
    }

    #[\Override]
    public function resolveFromRequest(): UsageEventContext
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof \Symfony\Component\HttpFoundation\Request) {
            return UsageEventContext::denied();
        }

        $user = $this->security->getUser();
        $appUser = $user instanceof User ? $user : null;
        $consent = $this->cookieConsentService->resolveForRequest($request, $appUser);
        if (!$this->cookieConsentService->hasAnalyticsConsent($consent)) {
            return UsageEventContext::denied();
        }

        $keys = $this->cookieManager->resolveKeys($request, true);
        $analyticsUserKey = null;
        if ($appUser instanceof User) {
            $userId = $appUser->getId();
            if (null !== $userId) {
                $analyticsUserKey = $this->userKeyGenerator->generate($userId);
            }
        }

        return new UsageEventContext(
            allowed: true,
            analyticsUserKey: $analyticsUserKey,
            visitorKey: $keys['visitorKey'],
            sessionKey: $keys['sessionKey'],
            userRole: $appUser instanceof User ? $this->resolvePrimaryRole($appUser) : null,
        );
    }

    /**
     * For async/worker contexts (no HTTP request / cookies).
     * Requires a prior analytics consent linked to the user.
     */
    #[\Override]
    public function resolveForUser(User $user): UsageEventContext
    {
        $userId = $user->getId();
        if (!$this->userHasAnalyticsConsent($user) || null === $userId) {
            return UsageEventContext::denied();
        }

        return new UsageEventContext(
            allowed: true,
            analyticsUserKey: $this->userKeyGenerator->generate($userId),
            visitorKey: null,
            sessionKey: null,
            userRole: $this->resolvePrimaryRole($user),
        );
    }

    private function userHasAnalyticsConsent(User $user): bool
    {
        return array_any(
            $this->cookieConsentRepository->findByUser($user),
            fn (\App\Shared\Domain\Entity\CookieConsent $consent): bool => $this->cookieConsentService->hasAnalyticsConsent($consent),
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
}
