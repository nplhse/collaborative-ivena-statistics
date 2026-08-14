<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Activity;

use App\User\Application\Activity\UserActivityDeduplicationKey;
use PHPUnit\Framework\TestCase;

final class UserActivityDeduplicationKeyTest extends TestCase
{
    public function testKeysAreStableAndDistinct(): void
    {
        self::assertSame('user:3:joined', UserActivityDeduplicationKey::joined(3));
        self::assertSame('user:3:import-milestone:10', UserActivityDeduplicationKey::importMilestone(3, 10));
        self::assertSame('user:3:post:8:published', UserActivityDeduplicationKey::postPublished(3, 8));
        self::assertSame('user:3:comment:9:created', UserActivityDeduplicationKey::commentCreated(3, 9));
        self::assertSame(
            'user:3:hospital:12:associated:41',
            UserActivityDeduplicationKey::hospitalAssociated(3, 12, 41),
        );
        self::assertSame(
            'user:3:hospital:12:disassociated:41',
            UserActivityDeduplicationKey::hospitalDisassociated(3, 12, 41),
        );
        self::assertSame('user:3:hospital:12:owner-granted', UserActivityDeduplicationKey::hospitalOwnerGranted(3, 12));
        self::assertSame('user:3:hospital:12:owner-revoked', UserActivityDeduplicationKey::hospitalOwnerRevoked(3, 12));
        self::assertNotSame(
            UserActivityDeduplicationKey::hospitalAssociated(3, 12, 41),
            UserActivityDeduplicationKey::hospitalAssociated(3, 12, 42),
        );
    }
}
