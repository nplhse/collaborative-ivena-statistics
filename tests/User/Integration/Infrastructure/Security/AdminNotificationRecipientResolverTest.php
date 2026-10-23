<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Security;

use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Contract\AdminNotificationRecipientResolverInterface;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;

final class AdminNotificationRecipientResolverTest extends DatabaseKernelTestCase
{
    public function testResolvesEveryEnabledVerifiedAdmin(): void
    {
        $first = UserFactory::createOne(['roles' => [UserRole::ADMIN]]);
        $second = UserFactory::createOne(['roles' => [UserRole::USER, UserRole::ADMIN]]);
        UserFactory::createOne(['roles' => [UserRole::USER, UserRole::PARTICIPANT]]);
        UserFactory::createOne(['roles' => [UserRole::ADMIN], 'isEnabled' => false]);
        UserFactory::createOne(['roles' => [UserRole::ADMIN], 'isVerified' => false]);

        $ids = self::getContainer()->get(AdminNotificationRecipientResolverInterface::class)->recipientIds();
        sort($ids);

        self::assertSame([$first->getId(), $second->getId()], $ids);
    }

    public function testReturnsNothingWithoutAdmins(): void
    {
        UserFactory::createOne(['roles' => [UserRole::USER]]);

        self::assertSame([], self::getContainer()->get(AdminNotificationRecipientResolverInterface::class)->recipientIds());
    }
}
