<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Query;

use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Query\UserIdsWithEffectiveRoleQuery;

final class UserIdsWithEffectiveRoleQueryTest extends DatabaseKernelTestCase
{
    public function testParticipantRoleIncludesInheritedAdminRole(): void
    {
        $participant = UserFactory::new()->asParticipant()->create();
        $admin = UserFactory::new()->asAdmin()->create();
        $disabledParticipant = UserFactory::new()->asParticipant()->create(['isEnabled' => false]);
        $plainUser = UserFactory::createOne(['roles' => []]);
        $boardMemberOnly = UserFactory::createOne(['roles' => [UserRole::BOARD_MEMBER]]);
        $closureBeta = UserFactory::createOne(['roles' => [UserRole::CLOSURE_BETA]]);

        $ids = (self::getContainer()->get(UserIdsWithEffectiveRoleQuery::class))(UserRole::PARTICIPANT);

        self::assertContains($participant->getId(), $ids);
        self::assertContains($admin->getId(), $ids, 'ROLE_ADMIN inherits ROLE_PARTICIPANT.');
        self::assertContains($disabledParticipant->getId(), $ids, 'The enabled flag is not part of the role check.');
        self::assertNotContains($plainUser->getId(), $ids);
        self::assertNotContains($boardMemberOnly->getId(), $ids);
        self::assertNotContains($closureBeta->getId(), $ids);
    }

    public function testEveryUserHasTheBaseRole(): void
    {
        $plainUser = UserFactory::createOne(['roles' => []]);

        $ids = (self::getContainer()->get(UserIdsWithEffectiveRoleQuery::class))(UserRole::USER);

        self::assertContains($plainUser->getId(), $ids);
    }
}
