<?php

declare(strict_types=1);

namespace App\Tests\Onboarding\Unit\Domain\Entity;

use App\Onboarding\Domain\Entity\UserWhatsNewDismissal;
use App\Onboarding\Domain\Entity\WhatsNewRelease;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Release rows and dismissals of the "What's new for you" card (#1485).
 */
final class WhatsNewEntitiesTest extends TestCase
{
    public function testReleaseKeepsItsKeyAndInstallTime(): void
    {
        $release = new WhatsNewRelease('v0.3', new \DateTimeImmutable('2026-11-01 00:00:00'));

        self::assertSame('v0.3', $release->getReleaseKey());
        self::assertEquals(new \DateTimeImmutable('2026-11-01 00:00:00'), $release->getInstalledAt());

        $release->setInstalledAt(new \DateTimeImmutable('2026-11-02 08:00:00'));
        self::assertEquals(new \DateTimeImmutable('2026-11-02 08:00:00'), $release->getInstalledAt());
    }

    public function testDismissalKeepsUserReleaseAndTime(): void
    {
        $user = new User();
        $dismissal = new UserWhatsNewDismissal($user, 'v0.3', new \DateTimeImmutable('2026-11-22 10:00:00'));

        self::assertNull($dismissal->getId());
        self::assertSame($user, $dismissal->getUser());
        self::assertSame('v0.3', $dismissal->getReleaseKey());
        self::assertEquals(new \DateTimeImmutable('2026-11-22 10:00:00'), $dismissal->getDismissedAt());
    }
}
