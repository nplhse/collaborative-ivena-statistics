<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Domain;

use App\Shared\Domain\Entity\CookieConsent;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class CookieConsentTest extends TestCase
{
    public function testNewConsentHasEssentialOnlyAndNoDecisionTimestamp(): void
    {
        $consent = new CookieConsent('abc123');

        self::assertSame('abc123', $consent->getSubjectId());
        self::assertNull($consent->getDecidedAt());
        self::assertSame(
            ['essential' => true, 'analytics' => false],
            $consent->getPreferences(),
        );
        self::assertSame('v1', $consent->getConsentVersion());
    }

    public function testSetConsentsRecordsDecisionAndPreferences(): void
    {
        $consent = new CookieConsent('subj');

        $consent->setConsents(true);

        self::assertInstanceOf(\DateTimeImmutable::class, $consent->getDecidedAt());
        self::assertSame(
            ['essential' => true, 'analytics' => true],
            $consent->getPreferences(),
        );

        $consent->setConsents(false);

        self::assertSame(
            ['essential' => true, 'analytics' => false],
            $consent->getPreferences(),
        );
    }

    public function testGetPreferencesDefaultsMissingAnalyticsKey(): void
    {
        $consent = new CookieConsent('legacy');
        $consent->setConsents(false);

        $reflection = new \ReflectionProperty($consent, 'preferences');
        $reflection->setValue($consent, [
            'essential' => true,
            'monitoring' => true,
        ]);

        self::assertSame(
            ['essential' => true, 'analytics' => false],
            $consent->getPreferences(),
        );
    }

    public function testSetUserIsFluent(): void
    {
        $user = new User();
        $user->setUsername('u');
        $user->setEmail('u@example.test');
        $user->setPassword('x');

        $consent = new CookieConsent('s');

        self::assertSame($consent, $consent->setUser($user));
        self::assertSame($user, $consent->getUser());
    }
}
