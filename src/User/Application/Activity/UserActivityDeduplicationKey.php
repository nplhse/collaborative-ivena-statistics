<?php

declare(strict_types=1);

namespace App\User\Application\Activity;

final class UserActivityDeduplicationKey
{
    public static function joined(int $userId): string
    {
        return sprintf('user:%d:joined', $userId);
    }

    public static function importMilestone(int $userId, int $rank): string
    {
        return sprintf('user:%d:import-milestone:%d', $userId, $rank);
    }

    public static function postPublished(int $userId, int $postId): string
    {
        return sprintf('user:%d:post:%d:published', $userId, $postId);
    }

    public static function commentCreated(int $userId, int $commentId): string
    {
        return sprintf('user:%d:comment:%d:created', $userId, $commentId);
    }

    public static function hospitalAssociated(int $userId, int $hospitalId, int $grantId): string
    {
        return sprintf('user:%d:hospital:%d:associated:%d', $userId, $hospitalId, $grantId);
    }

    public static function hospitalDisassociated(int $userId, int $hospitalId, int $grantId): string
    {
        return sprintf('user:%d:hospital:%d:disassociated:%d', $userId, $hospitalId, $grantId);
    }

    public static function hospitalOwnerGranted(int $userId, int $hospitalId): string
    {
        return sprintf('user:%d:hospital:%d:owner-granted', $userId, $hospitalId);
    }

    public static function hospitalOwnerRevoked(int $userId, int $hospitalId): string
    {
        return sprintf('user:%d:hospital:%d:owner-revoked', $userId, $hospitalId);
    }
}
