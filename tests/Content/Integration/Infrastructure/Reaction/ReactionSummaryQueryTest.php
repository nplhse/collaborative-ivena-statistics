<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Infrastructure\Reaction;

use App\Content\Application\Contract\ReactionSummaryQueryInterface;
use App\Content\Application\Reaction\ReactionCount;
use App\Content\Application\Reaction\ReactionParticipant;
use App\Content\Application\Reaction\ReactionTarget;
use App\Content\Domain\Entity\Reaction;
use App\Content\Domain\Enum\ReactionTargetType;
use App\Content\Domain\Enum\ReactionType;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Small fixture with explicit numbers:
 *
 * post 1: Like by anna, ben, cem (cem is disabled), Rocket by dana
 * post 2: Funny by ben
 * comment 1: Like by anna (same id as post 1, other target type)
 */
final class ReactionSummaryQueryTest extends DatabaseKernelTestCase
{
    private User $anna;
    private User $ben;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->anna = UserFactory::createOne(['username' => 'anna', 'displayName' => 'Anna A.']);
        $this->ben = UserFactory::createOne(['username' => 'ben', 'displayName' => null]);
        $cem = UserFactory::createOne(['username' => 'cem', 'isEnabled' => false]);
        $dana = UserFactory::createOne(['username' => 'dana', 'displayName' => '  ']);

        $this->react($this->anna, ReactionTargetType::Post, 1, ReactionType::Like, '2026-11-01 10:00:00');
        $this->react($this->ben, ReactionTargetType::Post, 1, ReactionType::Like, '2026-11-01 11:00:00');
        $this->react($cem, ReactionTargetType::Post, 1, ReactionType::Like, '2026-11-01 12:00:00');
        $this->react($dana, ReactionTargetType::Post, 1, ReactionType::Rocket, '2026-11-01 13:00:00');
        $this->react($this->ben, ReactionTargetType::Post, 2, ReactionType::Funny, '2026-11-02 10:00:00');
        $this->react($this->anna, ReactionTargetType::Comment, 1, ReactionType::Like, '2026-11-03 10:00:00');
    }

    public function testCountsPerReactionWithoutDisabledUsersInEnumOrder(): void
    {
        $summaries = $this->query()->summaries($this->targets(), (int) $this->ben->getId(), true);

        self::assertSame(['post:1', 'post:2', 'comment:1', 'post:3'], array_keys($summaries));
        self::assertSame(
            [['like', 2, true, ['Anna A.', 'ben']], ['rocket', 1, false, ['dana']]],
            $this->flatten($summaries['post:1']->counts),
            'cem is disabled and not counted; an empty display name falls back to the username',
        );
        self::assertSame(ReactionType::Like, $summaries['post:1']->own());
        self::assertSame([['funny', 1, true, ['ben']]], $this->flatten($summaries['post:2']->counts));
        self::assertSame([['like', 1, false, ['Anna A.']]], $this->flatten($summaries['comment:1']->counts), 'same id, other target type');
        self::assertSame([], $summaries['post:3']->counts, 'a target without reactions still gets a summary');
    }

    public function testVisitorsGetCountsWithoutNamesOrOwnReaction(): void
    {
        $summaries = $this->query()->summaries($this->targets(), null, false);

        self::assertSame([['like', 2, false, []], ['rocket', 1, false, []]], $this->flatten($summaries['post:1']->counts));
        self::assertNull($summaries['post:1']->own());
    }

    public function testNamesAreLimitedAndTheRestIsCounted(): void
    {
        for ($i = 1; $i <= ReactionSummaryQueryInterface::MAX_NAMES + 2; ++$i) {
            $this->react(UserFactory::createOne(['username' => \sprintf('user%02d', $i)]), ReactionTargetType::Post, 9, ReactionType::Eyes, \sprintf('2026-11-05 10:%02d:00', $i));
        }

        $count = $this->query()->summaries([new ReactionTarget(ReactionTargetType::Post, 9)], null, true)['post:9']->counts[0];

        self::assertSame(12, $count->count);
        self::assertCount(10, $count->names);
        self::assertSame('user01', $count->names[0], 'oldest reaction first');
        self::assertSame(2, $count->unnamed());
    }

    public function testIgnoresReactionTypesThatNoLongerExist(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            "INSERT INTO reaction (user_id, target_type, target_id, type, created_at, updated_at) VALUES (?, 'post', 2, 'removed', NOW(), NOW())",
            [$this->anna->getId()],
        );

        $summaries = $this->query()->summaries([new ReactionTarget(ReactionTargetType::Post, 2)], null, false);

        self::assertSame([['funny', 1, false, []]], $this->flatten($summaries['post:2']->counts));
    }

    public function testParticipantsAreTheLatestPeopleWithoutDisabledUsers(): void
    {
        $summaries = $this->query()->summaries($this->targets(), null, true);

        self::assertSame(['dana', 'ben', 'anna'], $this->usernames($summaries['post:1']->participants), 'newest first, cem is disabled');
        self::assertSame(3, $summaries['post:1']->participantCount());
        self::assertSame(['ben'], $this->usernames($summaries['post:2']->participants));
        self::assertSame(['anna'], $this->usernames($summaries['comment:1']->participants), 'same id, other target type');
        self::assertSame([], $summaries['post:3']->participants);
    }

    public function testParticipantsStopAtFourWhileTheCountStaysComplete(): void
    {
        foreach (['p1', 'p2', 'p3', 'p4', 'p5', 'p6'] as $index => $username) {
            $this->react(UserFactory::createOne(['username' => $username]), ReactionTargetType::Post, 9, 0 === $index % 2 ? ReactionType::Like : ReactionType::Love, '2026-11-05 10:00:00');
        }

        $summary = $this->query()->summaries([new ReactionTarget(ReactionTargetType::Post, 9)], null, true)['post:9'];

        self::assertSame(['p6', 'p5', 'p4', 'p3'], $this->usernames($summary->participants));
        self::assertSame(6, $summary->participantCount());
    }

    public function testVisitorsGetNoParticipants(): void
    {
        $summaries = $this->query()->summaries($this->targets(), null, false);

        self::assertSame([], $summaries['post:1']->participants);
        self::assertSame(3, $summaries['post:1']->participantCount());
    }

    /**
     * @param list<ReactionParticipant> $participants
     *
     * @return list<string>
     */
    private function usernames(array $participants): array
    {
        return array_map(static fn (ReactionParticipant $participant): string => $participant->username, $participants);
    }

    /**
     * @return list<ReactionTarget>
     */
    private function targets(): array
    {
        return [
            new ReactionTarget(ReactionTargetType::Post, 1),
            new ReactionTarget(ReactionTargetType::Post, 2),
            new ReactionTarget(ReactionTargetType::Comment, 1),
            new ReactionTarget(ReactionTargetType::Post, 3),
        ];
    }

    /**
     * @param list<ReactionCount> $counts
     *
     * @return list<array{string, int, bool, list<string>}>
     */
    private function flatten(array $counts): array
    {
        return array_map(static fn (ReactionCount $count): array => [$count->type->value, $count->count, $count->own, $count->names], $counts);
    }

    private function react(User $user, ReactionTargetType $targetType, int $targetId, ReactionType $type, string $at): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new Reaction($user, $targetType, $targetId, $type, new \DateTimeImmutable($at)));
        $entityManager->flush();
    }

    private function query(): ReactionSummaryQueryInterface
    {
        return self::getContainer()->get(ReactionSummaryQueryInterface::class);
    }
}
