<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Consent;

use App\Shared\Domain\Entity\CookieConsent;
use App\Shared\Infrastructure\Repository\CookieConsentRepository;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

final readonly class CookieConsentService
{
    public const string SUBJECT_COOKIE_NAME = 'consent_subject_id';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private CookieConsentRepository $repository,
    ) {
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

    public function applyPreference(CookieConsent $consent, bool $monitoring, ?User $user): void
    {
        if ($user instanceof User) {
            $consent->setUser($user);
        }
        $consent->setMonitoringConsent($monitoring);
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

    /** @psalm-suppress PossiblyUnusedMethod */
    public function buildSubjectCookie(Request $request, CookieConsent $consent): Cookie
    {
        return $this->buildSubjectCookieForSubjectId($request, $consent->getSubjectId());
    }

    public function buildSubjectCookieForSubjectId(Request $request, string $subjectId): Cookie
    {
        return Cookie::create(self::SUBJECT_COOKIE_NAME, $subjectId)
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite('lax')
            ->withExpires(new \DateTimeImmutable('+1 year'));
    }

    /**
     * @return array{
     *   decided: bool,
     *   preferences: array{essential: bool, monitoring: bool},
     *   consentVersion: string
     * }
     */
    public function describe(CookieConsent $consent): array
    {
        return [
            'decided' => $consent->getDecidedAt() instanceof \DateTimeImmutable,
            'preferences' => $consent->getPreferences(),
            'consentVersion' => $consent->getConsentVersion(),
        ];
    }

    private function resolveSubjectId(Request $request): string
    {
        $cookieValue = $request->cookies->get(self::SUBJECT_COOKIE_NAME);
        if (\is_string($cookieValue) && '' !== trim($cookieValue)) {
            return trim($cookieValue);
        }

        return bin2hex(random_bytes(16));
    }
}
