<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Query;

use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Query\TwoFactorRecommendationQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * For now two-factor authentication is recommended to administrators only (#1508). Hospital owners and import grantees
 * are plain users here: ownership and grants are no roles, and the query does not look at them. That an administrator
 * sees the badge, and not once two-factor authentication is on, is in `TwoFactorObligationAndResetTest`.
 */
#[CoversClass(TwoFactorRecommendationQuery::class)]
final class TwoFactorRecommendationQueryTest extends TestCase
{
    /** @return iterable<string, array{list<string>, bool}> */
    public static function roles(): iterable
    {
        yield 'administrator' => [[UserRole::ADMIN], true];
        yield 'plain user, hospital owner or import grantee' => [[], false];
        yield 'participant' => [[UserRole::PARTICIPANT], false];
        yield 'board member' => [[UserRole::BOARD_MEMBER], false];
        yield 'indication reviewer' => [[UserRole::REVIEW_INDICATIONS], false];
    }

    /** @param list<string> $roles */
    #[DataProvider('roles')]
    public function testOnlyAdministratorsGetTheRecommendation(array $roles, bool $recommended): void
    {
        $user = new User()->setUsername('alice')->setRoles($roles);

        $isRecommended = new TwoFactorRecommendationQuery()->isRecommendedFor($user);

        self::assertSame($recommended, $isRecommended);
    }
}
