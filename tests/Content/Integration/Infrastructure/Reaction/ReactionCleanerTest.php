<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Infrastructure\Reaction;

use App\Content\Application\Message\CleanUpReactionsMessage;
use App\Content\Domain\Entity\Reaction;
use App\Content\Domain\Enum\ReactionTargetType;
use App\Content\Domain\Enum\ReactionType;
use App\Content\Infrastructure\Factory\PostCommentFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\UserActivity;
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class ReactionCleanerTest extends DatabaseKernelTestCase
{
    public function testRemovesReactionsOfDeletedTargetsOnly(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = UserFactory::createOne();
        $kept = PostFactory::createOne();
        $deleted = PostFactory::createOne();
        $comment = PostCommentFactory::createOne(['post' => $kept]);
        $activity = new UserActivity($user, UserActivityType::JOINED, new \DateTimeImmutable('2026-11-01'), 'joined-cleanup');
        $entityManager->persist($activity);
        $entityManager->flush();

        $at = new \DateTimeImmutable('2026-11-10');
        foreach ([
            [ReactionTargetType::Post, (int) $kept->getId()],
            [ReactionTargetType::Post, (int) $deleted->getId()],
            [ReactionTargetType::Comment, (int) $comment->getId()],
            [ReactionTargetType::Comment, 999001],
            [ReactionTargetType::Activity, (int) $activity->getId()],
            [ReactionTargetType::Activity, 999002],
        ] as [$type, $id]) {
            $entityManager->persist(new Reaction($user, $type, $id, ReactionType::Like, $at));
        }
        $entityManager->flush();

        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('DELETE FROM post WHERE id = ?', [$deleted->getId()]);

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new CleanUpReactionsMessage());

        self::assertSame(
            [['activity', (int) $activity->getId()], ['comment', (int) $comment->getId()], ['post', (int) $kept->getId()]],
            array_map(
                static fn (array $row): array => [(string) $row['target_type'], (int) $row['target_id']],
                $connection->fetchAllAssociative('SELECT target_type, target_id FROM reaction ORDER BY target_type, target_id'),
            ),
        );
    }

    public function testDeletingAUserRemovesTheirReactions(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = UserFactory::createOne();
        $entityManager->persist(new Reaction($user, ReactionTargetType::Post, 1, ReactionType::Love, new \DateTimeImmutable('2026-11-10')));
        $entityManager->flush();

        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('DELETE FROM "user" WHERE id = ?', [$user->getId()]);

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM reaction'));
    }
}
