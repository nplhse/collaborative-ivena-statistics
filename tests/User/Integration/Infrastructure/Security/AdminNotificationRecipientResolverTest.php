<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Security;

use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Contract\AdminNotificationRecipientResolverInterface;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;

final class AdminNotificationRecipientResolverTest extends DatabaseKernelTestCase
{
    public function testResolvesEnabledVerifiedAdminsWithTheOptInRole(): void
    {
        $system = UserFactory::createOne(['roles' => [UserRole::ADMIN, UserRole::RECEIVES_NOTIFICATION]]);
        $feedback = UserFactory::createOne(['roles' => [UserRole::ADMIN, UserRole::FEEDBACK_RECIPIENT]]);
        $both = UserFactory::createOne(['roles' => [UserRole::ADMIN, UserRole::RECEIVES_NOTIFICATION, UserRole::FEEDBACK_RECIPIENT]]);
        UserFactory::createOne(['roles' => [UserRole::ADMIN]]);
        UserFactory::createOne(['roles' => [UserRole::RECEIVES_NOTIFICATION, UserRole::FEEDBACK_RECIPIENT]]);
        UserFactory::createOne(['roles' => [UserRole::ADMIN, UserRole::RECEIVES_NOTIFICATION, UserRole::FEEDBACK_RECIPIENT], 'isEnabled' => false]);
        UserFactory::createOne(['roles' => [UserRole::ADMIN, UserRole::RECEIVES_NOTIFICATION, UserRole::FEEDBACK_RECIPIENT], 'isVerified' => false]);

        $resolver = self::getContainer()->get(AdminNotificationRecipientResolverInterface::class);

        $systemIds = $resolver->systemRecipientIds();
        sort($systemIds);
        $feedbackIds = $resolver->feedbackRecipientIds();
        sort($feedbackIds);

        self::assertSame([$system->getId(), $both->getId()], $systemIds);
        self::assertSame([$feedback->getId(), $both->getId()], $feedbackIds);
    }

    public function testReturnsNothingWithoutAdmins(): void
    {
        UserFactory::createOne(['roles' => [UserRole::USER]]);

        $resolver = self::getContainer()->get(AdminNotificationRecipientResolverInterface::class);

        self::assertSame([], $resolver->systemRecipientIds());
        self::assertSame([], $resolver->feedbackRecipientIds());
    }
}
