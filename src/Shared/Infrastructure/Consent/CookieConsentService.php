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

    public function getOrCreateForRequest(Request $request, ?User $user): CookieConsent
    {
        $subjectId = $this->resolveSubjectId($request);
        $consent = $this->repository->findOneBySubjectId($subjectId);

        if (!$consent instanceof CookieConsent) {
            $consent = new CookieConsent($subjectId);
        }

        if ($user instanceof User && $consent->getUser() !== $user) {
            $consent->setUser($user);
        }

        $this->repository->save($consent);

        return $consent;
    }

    public function applyPreference(CookieConsent $consent, bool $monitoring): void
    {
        $consent->setMonitoringConsent($monitoring);
        $this->repository->save($consent);
    }

    public function buildSubjectCookie(Request $request, CookieConsent $consent): Cookie
    {
        return Cookie::create(self::SUBJECT_COOKIE_NAME, $consent->getSubjectId())
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite('lax')
            ->withExpires(new \DateTimeImmutable('+1 year'));
    }

    private function resolveSubjectId(Request $request): string
    {
        $cookieValue = $request->cookies->get(self::SUBJECT_COOKIE_NAME);
        if (\is_string($cookieValue) && '' !== trim($cookieValue)) {
            return $cookieValue;
        }

        return bin2hex(random_bytes(16));
    }
}
