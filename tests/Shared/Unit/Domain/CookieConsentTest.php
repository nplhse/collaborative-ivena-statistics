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
        self::assertSame(['essential' => true, 'monitoring' => false], $consent->getPreferences());
        self::assertSame('v1', $consent->getConsentVersion());
    }

    public function testSetMonitoringConsentRecordsDecisionAndPreferences(): void
    {
        $consent = new CookieConsent('subj');

        $consent->setMonitoringConsent(true);

        self::assertInstanceOf(\DateTimeImmutable::class, $consent->getDecidedAt());
        self::assertSame(['essential' => true, 'monitoring' => true], $consent->getPreferences());

        $consent->setMonitoringConsent(false);

        self::assertSame(['essential' => true, 'monitoring' => false], $consent->getPreferences());
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
