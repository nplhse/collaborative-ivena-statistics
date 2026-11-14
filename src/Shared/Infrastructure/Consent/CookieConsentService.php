<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Consent;

use App\Shared\Domain\Entity\CookieConsent;
use App\Shared\Infrastructure\Monitoring\Sentry\SentryMonitoringSettings;
use App\Shared\Infrastructure\Repository\CookieConsentRepository;
use App\User\Domain\Entity\User;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

final readonly class CookieConsentService
{
    /** Cookie name without the prefix of app.cookie_prefix (empty except in the demo environment). */
    public const string SUBJECT_COOKIE_NAME = 'consent_subject_id';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private CookieConsentRepository $repository,
        private ClockInterface $clock,
        #[Autowire(param: 'app.cookie_prefix')]
        private string $cookiePrefix = '',
        private SentryMonitoringSettings $monitoring = new SentryMonitoringSettings(),
    ) {
    }

    public function subjectCookieName(): string
    {
        return $this->cookiePrefix.self::SUBJECT_COOKIE_NAME;
    }

    public function resolveForRequest(Request $request, ?User $user): CookieConsent
    {
        $subjectId = $this->resolveSubjectId($request);
        $request->attributes->set('cookie_consent_subject_id', $subjectId);

        $consent = $this->repository->findOneBySubjectId($subjectId);
        if ($consent instanceof CookieConsent) {
            return $consent;
        }

        $consent = new CookieConsent($subjectId);
        if ($user instanceof User) {
            $consent->setUser($user);
        }

        return $consent;
    }

    /**
     * The performance option is only offered, and therefore only stored, while Sentry is used.
     */
    public function applyPreference(CookieConsent $consent, bool $analytics, ?User $user, bool $performance = false): void
    {
        if ($user instanceof User) {
            $consent->setUser($user);
        }
        $consent->setConsents($analytics, $performance && $this->monitoring->isEnabled());
        $this->repository->save($consent);
    }

    public function attachUser(CookieConsent $consent, User $user): void
    {
        if (!$consent->getDecidedAt() instanceof \DateTimeImmutable) {
            return;
        }

        if ($consent->getUser()?->getId() === $user->getId()) {
            return;
        }

        $consent->setUser($user);
        $this->repository->save($consent);
    }

    public function hasAnalyticsConsent(CookieConsent $consent): bool
    {
        return $consent->getDecidedAt() instanceof \DateTimeImmutable
            && ($consent->getPreferences()['analytics'] ?? false);
    }

    /**
     * Consent to performance traces in Sentry that carry the internal user id. Error reports carry it regardless.
     */
    public function hasPerformanceConsent(CookieConsent $consent): bool
    {
        return $this->hasAnalyticsConsent($consent) && $consent->getPreferences()['performance'];
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function buildSubjectCookie(Request $request, CookieConsent $consent): Cookie
    {
        return $this->buildSubjectCookieForSubjectId($request, $consent->getSubjectId());
    }

    public function buildSubjectCookieForSubjectId(Request $request, string $subjectId): Cookie
    {
        return Cookie::create($this->subjectCookieName(), $subjectId)
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX)
            ->withExpires($this->clock->now()->modify('+1 year'));
    }

    /**
     * @return array{
     *   decided: bool,
     *   preferences: array{essential: bool, analytics: bool, performance: bool},
     *   consentVersion: string,
     *   monitoring: array{enabled: bool, location: string|null}
     * }
     */
    public function describe(CookieConsent $consent): array
    {
        return [
            'decided' => $consent->getDecidedAt() instanceof \DateTimeImmutable,
            'preferences' => $consent->getPreferences(),
            'consentVersion' => $consent->getConsentVersion(),
            'monitoring' => [
                'enabled' => $this->monitoring->isEnabled(),
                'location' => $this->monitoring->location(),
            ],
        ];
    }

    private function resolveSubjectId(Request $request): string
    {
        $cookieValue = $request->cookies->get($this->subjectCookieName());
        if (\is_string($cookieValue) && '' !== trim($cookieValue)) {
            return trim($cookieValue);
        }

        return bin2hex(random_bytes(16));
    }
}
