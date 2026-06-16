<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Security;

use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Security\NotificationRecipientResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class NotificationRecipientResolverTest extends KernelTestCase
{
    use Factories;

    public function testResolvesEnabledVerifiedAdminWithNotificationRole(): void
    {
        UserFactory::new([
            'email' => 'notify@example.test',
            'roles' => [UserRole::ADMIN, UserRole::RECEIVES_NOTIFICATION],
            'isEnabled' => true,
            'isVerified' => true,
        ])->create();

        UserFactory::new([
            'email' => 'admin-only@example.test',
            'roles' => [UserRole::ADMIN],
            'isEnabled' => true,
            'isVerified' => true,
        ])->create();

        UserFactory::new([
            'email' => 'disabled@example.test',
            'roles' => [UserRole::ADMIN, UserRole::RECEIVES_NOTIFICATION],
            'isEnabled' => false,
            'isVerified' => true,
        ])->create();

        $resolver = self::getContainer()->get(NotificationRecipientResolver::class);

        self::assertSame(['notify@example.test'], $resolver->resolveRecipientEmails());
    }

    public function testDeduplicatesEmails(): void
    {
        UserFactory::new([
            'email' => 'DUPLICATE@example.test',
            'roles' => [UserRole::ADMIN, UserRole::RECEIVES_NOTIFICATION],
        ])->create();

        $resolver = self::getContainer()->get(NotificationRecipientResolver::class);

        self::assertSame(['duplicate@example.test'], $resolver->resolveRecipientEmails());
    }

    public function testReturnsEmptyListWhenNoMatchingUsers(): void
    {
        UserFactory::new([
            'email' => 'user@example.test',
            'roles' => [UserRole::USER],
        ])->create();

        $resolver = self::getContainer()->get(NotificationRecipientResolver::class);

        self::assertSame([], $resolver->resolveRecipientEmails());
    }
}
