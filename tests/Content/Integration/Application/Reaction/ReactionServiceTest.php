<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Application\Reaction;

use App\Content\Application\Event\ReactionChanged;
use App\Content\Application\Reaction\ReactionService;
use App\Content\Application\Reaction\ReactionTarget;
use App\Content\Application\Reaction\ReactionTargetUnavailableException;
use App\Content\Domain\Entity\Reaction;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Domain\Enum\ReactionTargetType;
use App\Content\Domain\Enum\ReactionType;
use App\Content\Infrastructure\Factory\PostCommentFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Entity\UserActivity;
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class ReactionServiceTest extends DatabaseKernelTestCase
{
    use ClockSensitiveTrait;

    /** @var list<ReactionChanged> */
    private array $events = [];

    #[\Override]
    protected function setUp(): void
    {
        self::mockTime('2026-11-21 12:00:00');
        self::bootKernel();
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(ReactionChanged::class, function (ReactionChanged $event): void {
            $this->events[] = $event;
        });
    }

    public function testSetsReplacesAndRemovesTheReaction(): void
    {
        $user = UserFactory::createOne();
        $post = PostFactory::createOne();
        $target = new ReactionTarget(ReactionTargetType::Post, (int) $post->getId());

        $this->service()->toggle($user, $target, ReactionType::Like);
        self::assertSame([ReactionType::Like], $this->storedTypes());

        $this->service()->toggle($user, $target, ReactionType::Rocket);
        self::assertSame([ReactionType::Rocket], $this->storedTypes(), 'another reaction replaces the first one');

        $this->service()->toggle($user, $target, ReactionType::Rocket);
        self::assertSame([], $this->storedTypes(), 'the same reaction again removes it');

        self::assertSame(
            [[null, ReactionType::Like], [ReactionType::Like, ReactionType::Rocket], [ReactionType::Rocket, null]],
            array_map(static fn (ReactionChanged $event): array => [$event->previous, $event->current], $this->events),
        );
        self::assertSame($post->getId(), $this->events[0]->targetId);
        self::assertSame($user->getId(), $this->events[0]->userId);
    }

    public function testUsersReactIndependently(): void
    {
        $post = PostFactory::createOne();
        $target = new ReactionTarget(ReactionTargetType::Post, (int) $post->getId());

        $this->service()->toggle(UserFactory::createOne(), $target, ReactionType::Like);
        $this->service()->toggle(UserFactory::createOne(), $target, ReactionType::Like);

        self::assertSame([ReactionType::Like, ReactionType::Like], $this->storedTypes());
    }

    public function testRefusesPostsThatAreNotPublished(): void
    {
        $user = UserFactory::createOne();
        $draft = PostFactory::createOne(['status' => PostStatus::DRAFT]);
        $scheduled = PostFactory::createOne(['publishedAt' => new \DateTimeImmutable('2026-11-22 08:00:00')]);

        foreach ([$draft, $scheduled] as $post) {
            try {
                $this->service()->toggle($user, new ReactionTarget(ReactionTargetType::Post, (int) $post->getId()), ReactionType::Like);
                self::fail('A post that is not published must be refused.');
            } catch (ReactionTargetUnavailableException) {
            }
        }

        self::assertSame([], $this->storedTypes());
        self::assertSame([], $this->events);
    }

    public function testRefusesCommentsOfUnpublishedPostsAndMissingTargets(): void
    {
        $user = UserFactory::createOne();
        $comment = PostCommentFactory::createOne(['post' => PostFactory::createOne(['status' => PostStatus::DRAFT])]);

        $this->expectException(ReactionTargetUnavailableException::class);
        $this->service()->toggle($user, new ReactionTarget(ReactionTargetType::Comment, (int) $comment->getId()), ReactionType::Like);
    }

    public function testAcceptsCommentsOfPublishedPosts(): void
    {
        $comment = PostCommentFactory::createOne(['post' => PostFactory::createOne()]);

        $this->service()->toggle(UserFactory::createOne(), new ReactionTarget(ReactionTargetType::Comment, (int) $comment->getId()), ReactionType::Funny);

        self::assertSame([ReactionType::Funny], $this->storedTypes());
    }

    public function testRefusesActivitiesOfDisabledUsers(): void
    {
        $visible = $this->activity(UserFactory::createOne());
        $hidden = $this->activity(UserFactory::createOne(['isEnabled' => false]));
        $user = UserFactory::createOne();

        $this->service()->toggle($user, new ReactionTarget(ReactionTargetType::Activity, $visible), ReactionType::Hooray);

        $this->expectException(ReactionTargetUnavailableException::class);
        $this->service()->toggle($user, new ReactionTarget(ReactionTargetType::Activity, $hidden), ReactionType::Hooray);
    }

    public function testRefusesUnknownIds(): void
    {
        $this->expectException(ReactionTargetUnavailableException::class);
        $this->service()->toggle(UserFactory::createOne(), new ReactionTarget(ReactionTargetType::Post, 999999), ReactionType::Like);
    }

    private function activity(User $user): int
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $activity = new UserActivity($user, UserActivityType::JOINED, new \DateTimeImmutable('2026-11-01 10:00:00'), 'joined-'.$user->getId());
        $entityManager->persist($activity);
        $entityManager->flush();

        return (int) $activity->getId();
    }

    private function service(): ReactionService
    {
        return self::getContainer()->get(ReactionService::class);
    }

    /**
     * @return list<ReactionType>
     */
    private function storedTypes(): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return array_map(static fn (Reaction $reaction): ReactionType => $reaction->getType(), $entityManager->getRepository(Reaction::class)->findBy([], ['id' => 'ASC']));
    }
}
