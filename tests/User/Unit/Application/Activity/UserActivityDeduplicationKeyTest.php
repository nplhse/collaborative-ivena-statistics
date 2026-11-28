<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Activity;

use App\User\Application\Activity\UserActivityDeduplicationKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The keys are stored in user_activity and prevent duplicate rows, so their format must not change.
 */
#[CoversClass(UserActivityDeduplicationKey::class)]
final class UserActivityDeduplicationKeyTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(): string, string}>
     */
    public static function keys(): iterable
    {
        yield 'joined' => [static fn (): string => UserActivityDeduplicationKey::joined(3), 'user:3:joined'];
        yield 'import milestone' => [static fn (): string => UserActivityDeduplicationKey::importMilestone(3, 10), 'user:3:import-milestone:10'];
        yield 'post published' => [static fn (): string => UserActivityDeduplicationKey::postPublished(3, 8), 'user:3:post:8:published'];
        yield 'comment created' => [static fn (): string => UserActivityDeduplicationKey::commentCreated(3, 9), 'user:3:comment:9:created'];
        yield 'hospital associated' => [static fn (): string => UserActivityDeduplicationKey::hospitalAssociated(3, 12, 41), 'user:3:hospital:12:associated:41'];
        yield 'hospital disassociated' => [static fn (): string => UserActivityDeduplicationKey::hospitalDisassociated(3, 12, 41), 'user:3:hospital:12:disassociated:41'];
        yield 'hospital owner granted' => [static fn (): string => UserActivityDeduplicationKey::hospitalOwnerGranted(3, 12), 'user:3:hospital:12:owner-granted'];
        yield 'hospital owner revoked' => [static fn (): string => UserActivityDeduplicationKey::hospitalOwnerRevoked(3, 12), 'user:3:hospital:12:owner-revoked'];
        // The last number is the access grant, so a second grant for the same hospital is a separate activity.
        yield 'hospital associated by another grant' => [static fn (): string => UserActivityDeduplicationKey::hospitalAssociated(3, 12, 42), 'user:3:hospital:12:associated:42'];
    }

    #[DataProvider('keys')]
    public function testKeyFormat(\Closure $build, string $expected): void
    {
        $key = $build();

        self::assertSame($expected, $key);
    }
}
