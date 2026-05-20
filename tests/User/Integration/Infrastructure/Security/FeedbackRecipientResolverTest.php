<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Security;

use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Security\FeedbackRecipientResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class FeedbackRecipientResolverTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testResolvesEnabledVerifiedAdminWithFeedbackRole(): void
    {
        UserFactory::new([
            'email' => 'recipient@example.test',
            'roles' => [UserRole::ADMIN, UserRole::FEEDBACK_RECIPIENT],
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
            'roles' => [UserRole::ADMIN, UserRole::FEEDBACK_RECIPIENT],
            'isEnabled' => false,
            'isVerified' => true,
        ])->create();

        $resolver = self::getContainer()->get(FeedbackRecipientResolver::class);

        self::assertSame(['recipient@example.test'], $resolver->resolveRecipientEmails());
    }

    public function testDeduplicatesEmails(): void
    {
        UserFactory::new([
            'email' => 'DUPLICATE@example.test',
            'roles' => [UserRole::ADMIN, UserRole::FEEDBACK_RECIPIENT],
        ])->create();

        $resolver = self::getContainer()->get(FeedbackRecipientResolver::class);

        self::assertSame(['duplicate@example.test'], $resolver->resolveRecipientEmails());
    }

    public function testReturnsEmptyListWhenNoMatchingUsers(): void
    {
        UserFactory::new([
            'email' => 'user@example.test',
            'roles' => [UserRole::USER],
        ])->create();

        $resolver = self::getContainer()->get(FeedbackRecipientResolver::class);

        self::assertSame([], $resolver->resolveRecipientEmails());
    }
}
