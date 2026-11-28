<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Query;

use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Activity\UserActivityDeduplicationKey;
use App\User\Application\Explore\ProfileActivity;
use App\User\Application\Explore\ProfileActivityCursor;
use App\User\Application\Explore\ProfileActivityPage;
use App\User\Domain\Entity\User;
use App\User\Domain\Entity\UserActivity;
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Query\UserProfileActivityQuery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The activity list on a user profile: newest first, "joined" always last. The feed hides posts whose publication
 * lies after now, so every test fixes the time; the fixtures lie before it unless a test is about that boundary.
 */
final class UserProfileActivityQueryTest extends DatabaseKernelTestCase
{
    use ClockSensitiveTrait;

    private const string NOW = '2026-08-01 12:00:00';

    public function testFirstPageShowsTheTwentyNewestAndKeepsJoinedForTheEnd(): void
    {
        self::mockTime(self::NOW);
        $user = $this->userWithTwentyFivePosts();

        $page = $this->query()->getPage($user, null, ProfileActivityPage::PAGE_SIZE);

        self::assertSame($this->posts(25, 6), $this->items($page));
        self::assertTrue($page->hasMore());
    }

    public function testNextPageContinuesWithoutGapsOrRepeatsAndEndsWithJoined(): void
    {
        self::mockTime(self::NOW);
        $user = $this->userWithTwentyFivePosts();
        $first = $this->query()->getPage($user, null, ProfileActivityPage::PAGE_SIZE);

        $second = $this->query()->getPage($user, $first->nextCursor, ProfileActivityPage::PAGE_SIZE);

        // The first import happened at the same time as joining, but joined is always last.
        self::assertSame([...$this->posts(5, 1), 'first_import A', 'joined'], $this->items($second));
        self::assertFalse($second->hasMore());
    }

    public function testLegacyCursorStartsOnTheFirstPage(): void
    {
        self::mockTime(self::NOW);
        $user = $this->userWithTwentyFivePosts();
        // Version 1 paged by type and padded stable id (see ProfileActivityCursorTest).
        $legacy = base64_encode(json_encode([
            'v' => 1,
            'occurredAt' => '2026-01-25T12:00:00+00:00',
            'type' => 'post_published',
            'stableId' => ProfileActivityCursor::padId(25),
        ], JSON_THROW_ON_ERROR));

        $page = $this->query()->getPage($user, $legacy, ProfileActivityPage::PAGE_SIZE);

        self::assertSame($this->posts(25, 6), $this->items($page));
    }

    public function testHospitalAndContentMetadataAreMapped(): void
    {
        self::mockTime(self::NOW);
        $user = UserFactory::createOne();
        $userId = $this->id($user);
        $this->record($user, UserActivityType::HOSPITAL_ASSOCIATED, '2026-03-01 09:00:00', UserActivityDeduplicationKey::hospitalAssociated($userId, 12, 41), ['hospitalName' => 'Grant Klinik', 'hospitalPublicId' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb']);
        $this->record($user, UserActivityType::COMMENT_CREATED, '2026-03-02 09:00:00', UserActivityDeduplicationKey::commentCreated($userId, 9), ['postTitle' => 'Artikel', 'postSlug' => 'artikel', 'excerpt' => 'Hi']);
        $this->record($user, UserActivityType::JOINED, '2026-01-01 00:00:00', UserActivityDeduplicationKey::joined($userId));
        $this->flush();

        $page = $this->query()->getPage($user, null);

        self::assertSame(['comment_created Artikel', 'hospital_associated Grant Klinik', 'joined'], $this->items($page));
        self::assertSame('Hi', $page->items[0]->excerpt);
        self::assertSame('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', $page->items[1]->hospitalPublicId);
    }

    public function testHidesPostsPublishedAfterNow(): void
    {
        self::mockTime('2026-05-15 10:00:00');
        $user = UserFactory::createOne(['username' => 'profile-feed-scheduled']);
        $userId = $this->id($user);
        $this->record($user, UserActivityType::POST_PUBLISHED, '2026-05-15 09:00:00', UserActivityDeduplicationKey::postPublished($userId, 501), ['title' => 'Already Live', 'slug' => 'already-live']);
        // Exactly now counts as published.
        $this->record($user, UserActivityType::POST_PUBLISHED, '2026-05-15 10:00:00', UserActivityDeduplicationKey::postPublished($userId, 502), ['title' => 'Publishing Now', 'slug' => 'publishing-now']);
        $this->record($user, UserActivityType::POST_PUBLISHED, '2026-05-15 10:00:01', UserActivityDeduplicationKey::postPublished($userId, 503), ['title' => 'Scheduled Headline', 'slug' => 'scheduled-headline']);
        // Only posts wait for their time; other activity in the future is shown.
        $this->record($user, UserActivityType::COMMENT_CREATED, '2026-05-17 10:00:00', UserActivityDeduplicationKey::commentCreated($userId, 88), ['postTitle' => 'Future Comment Post', 'postSlug' => 'future-comment', 'excerpt' => 'Later']);
        $this->flush();

        $page = $this->query()->getPage($user, null);

        self::assertSame(['comment_created Future Comment Post', 'post_published Publishing Now', 'post_published Already Live'], $this->items($page));
    }

    /**
     * Joined and the first import at the same time (17 Apr 2022), "Post 1" to "Post 25" on 1 to 25 Jan 2026.
     */
    private function userWithTwentyFivePosts(): User
    {
        $user = UserFactory::createOne(['createdAt' => new \DateTimeImmutable('2022-04-17 10:00:00')]);
        $userId = $this->id($user);
        $this->record($user, UserActivityType::JOINED, '2022-04-17 10:00:00', UserActivityDeduplicationKey::joined($userId));
        $this->record($user, UserActivityType::FIRST_IMPORT, '2022-04-17 10:00:00', UserActivityDeduplicationKey::importMilestone($userId, 1), ['milestone' => 1, 'hospitalName' => 'A', 'hospitalPublicId' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa']);
        for ($i = 1; $i <= 25; ++$i) {
            $this->record($user, UserActivityType::POST_PUBLISHED, \sprintf('2026-01-%02d 12:00:00', $i), UserActivityDeduplicationKey::postPublished($userId, $i), ['title' => 'Post '.$i, 'slug' => 'post-'.$i, 'postId' => $i]);
        }
        $this->flush();

        return $user;
    }

    /**
     * @return list<string> "post_published Post n" from $from down to $to
     */
    private function posts(int $from, int $to): array
    {
        return array_map(static fn (int $number): string => 'post_published Post '.$number, range($from, $to));
    }

    /**
     * @return list<string> type and the post title or hospital name, if any, per item
     */
    private function items(ProfileActivityPage $page): array
    {
        return array_map(
            static fn (ProfileActivity $item): string => trim($item->type->value.' '.($item->postTitle ?? $item->hospitalName ?? '')),
            $page->items,
        );
    }

    private function query(): UserProfileActivityQuery
    {
        return self::getContainer()->get(UserProfileActivityQuery::class);
    }

    private function id(User $user): int
    {
        $id = $user->getId();
        self::assertNotNull($id);

        return $id;
    }

    /**
     * Persists only; the test flushes once after the last record.
     *
     * @param array<string, scalar|null> $metadata
     */
    private function record(User $user, UserActivityType $type, string $occurredAt, string $deduplicationKey, array $metadata = []): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->persist(new UserActivity(
            user: $user,
            type: $type,
            occurredAt: new \DateTimeImmutable($occurredAt),
            deduplicationKey: $deduplicationKey,
            metadata: $metadata,
        ));
    }

    private function flush(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }
}
