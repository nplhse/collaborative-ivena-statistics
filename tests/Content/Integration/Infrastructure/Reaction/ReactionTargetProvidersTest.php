<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Infrastructure\Reaction;

use App\Content\Application\Reaction\ReactionTargetRegistry;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Domain\Enum\ReactionTargetType;
use App\Content\Infrastructure\Factory\PostCommentFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\UserActivity;
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

final class ReactionTargetProvidersTest extends DatabaseKernelTestCase
{
    public function testEveryTargetTypeHasAProvider(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(ReactionTargetRegistry::class);

        foreach (ReactionTargetType::cases() as $type) {
            self::assertSame($type, $registry->get($type)?->targetType());
        }
        self::assertCount(\count(ReactionTargetType::cases()), $registry->all());
    }

    public function testEmptyInputNeedsNoQuery(): void
    {
        self::bootKernel();
        $viewer = UserFactory::createOne();

        foreach (self::getContainer()->get(ReactionTargetRegistry::class)->all() as $provider) {
            self::assertSame([], $provider->visibleIds([], $viewer), $provider->targetType()->value);
            self::assertSame([], $provider->existingIds([]), $provider->targetType()->value);
        }
    }

    public function testExistingIdsIgnoreVisibility(): void
    {
        self::bootKernel();
        $draft = PostFactory::createOne(['status' => PostStatus::DRAFT]);
        $comment = PostCommentFactory::createOne(['post' => $draft]);
        $user = UserFactory::createOne(['isEnabled' => false]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $activity = new UserActivity($user, UserActivityType::JOINED, new \DateTimeImmutable('2026-11-01'), 'joined-provider-test');
        $entityManager->persist($activity);
        $entityManager->flush();
        $registry = self::getContainer()->get(ReactionTargetRegistry::class);

        self::assertSame([(int) $draft->getId()], $registry->get(ReactionTargetType::Post)?->existingIds([(int) $draft->getId(), 999001]));
        self::assertSame([(int) $comment->getId()], $registry->get(ReactionTargetType::Comment)?->existingIds([(int) $comment->getId(), 999002]));
        self::assertSame([(int) $activity->getId()], $registry->get(ReactionTargetType::Activity)?->existingIds([(int) $activity->getId(), 999003]));
        self::assertSame([], $registry->get(ReactionTargetType::Activity)?->visibleIds([(int) $activity->getId()], null), 'visitors see no activities');
    }
}
