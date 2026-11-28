<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Query;

use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Activity\UserActivityDeduplicationKey;
use App\User\Application\Explore\ProfileActivityType;
use App\User\Application\Explore\ProjectActivity;
use App\User\Application\Explore\ProjectActivityFilters;
use App\User\Application\Explore\ProjectActivityPage;
use App\User\Domain\Entity\User;
use App\User\Domain\Entity\UserActivity;
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Query\ProjectActivityQuery;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The project activity feed (dashboard and `/explore/activity`) and the hospital feed (#488). The feed hides posts
 * whose publication lies after now, so every test fixes the time; the fixtures lie before it unless a test is about
 * that boundary.
 */
final class ProjectActivityQueryTest extends DatabaseKernelTestCase
{
    use ClockSensitiveTrait;

    private const string NOW = '2026-08-01 12:00:00';

    public function testFirstPageShowsTheTenNewestFeedActivities(): void
    {
        self::mockTime(self::NOW);
        $this->twelvePostsAndRemovedAccess();

        $page = $this->query()->getPage(null, ProjectActivityPage::PAGE_SIZE);

        // Removed access and ownership of bob in March are not part of the project feed.
        self::assertSame([
            'post_published Post 12', 'post_published Post 11', 'post_published Post 10', 'post_published Post 9',
            'post_published Post 8', 'post_published Post 7', 'post_published Post 6', 'post_published Post 5',
            'post_published Post 4', 'post_published Post 3',
        ], $this->items($page));
        self::assertSame('project-feed-alice', $page->items[0]->actorUsername);
        self::assertTrue($page->hasMore());
    }

    public function testNextPageContinuesWithoutGapsOrRepeats(): void
    {
        self::mockTime(self::NOW);
        $this->twelvePostsAndRemovedAccess();
        $first = $this->query()->getPage(null, ProjectActivityPage::PAGE_SIZE);

        $second = $this->query()->getPage($first->nextCursor, ProjectActivityPage::PAGE_SIZE);

        self::assertSame(['post_published Post 2', 'post_published Post 1', 'joined'], $this->items($second));
        self::assertFalse($second->hasMore());
    }

    public function testSkipsDisabledUsersAndExcludedTypes(): void
    {
        self::mockTime(self::NOW);
        $enabled = UserFactory::createOne(['username' => 'project-feed-enabled']);
        $disabled = UserFactory::createOne(['username' => 'project-feed-disabled', 'isEnabled' => false]);
        $enabledId = $this->id($enabled);
        $this->record($enabled, UserActivityType::COMMENT_CREATED, '2026-04-01 09:00:00', UserActivityDeduplicationKey::commentCreated($enabledId, 3), ['postTitle' => 'Artikel', 'postSlug' => 'artikel', 'excerpt' => 'Hi']);
        $this->record($disabled, UserActivityType::POST_PUBLISHED, '2026-04-02 09:00:00', UserActivityDeduplicationKey::postPublished($this->id($disabled), 99), ['title' => 'Hidden', 'slug' => 'hidden']);
        $this->record($enabled, UserActivityType::HOSPITAL_DISASSOCIATED, '2026-04-03 09:00:00', UserActivityDeduplicationKey::hospitalDisassociated($enabledId, 4, 8), ['hospitalName' => 'Secret']);
        $this->flush();

        $page = $this->query()->getPage(null);

        self::assertSame(['comment_created Artikel'], $this->items($page));
        self::assertSame(['project-feed-enabled', 'Hi'], [$page->items[0]->actorUsername, $page->items[0]->excerpt]);
    }

    public function testLimitBelowOneReturnsAnEmptyPage(): void
    {
        self::mockTime(self::NOW);
        $user = UserFactory::createOne();
        $this->record($user, UserActivityType::JOINED, '2026-05-01 08:00:00', UserActivityDeduplicationKey::joined($this->id($user)));
        $this->flush();

        $page = $this->query()->getPage(null, 0);

        self::assertSame([[], null], [$page->items, $page->nextCursor]);
    }

    public function testMapsHospitalAndMilestoneMetadata(): void
    {
        self::mockTime(self::NOW);
        $user = UserFactory::createOne(['username' => 'project-feed-meta']);
        $userId = $this->id($user);
        $hospital = ['hospitalName' => 'Grant Klinik', 'hospitalPublicId' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'];
        $this->record($user, UserActivityType::HOSPITAL_ASSOCIATED, '2026-05-01 08:00:00', UserActivityDeduplicationKey::hospitalAssociated($userId, 12, 41), $hospital);
        $this->record($user, UserActivityType::HOSPITAL_OWNER_GRANTED, '2026-05-02 08:00:00', UserActivityDeduplicationKey::hospitalOwnerGranted($userId, 12), $hospital);
        $this->record($user, UserActivityType::IMPORT_MILESTONE, '2026-05-03 08:00:00', UserActivityDeduplicationKey::importMilestone($userId, 5), ['milestone' => 5] + $hospital);
        $this->flush();

        $page = $this->query()->getPage(null);

        self::assertSame(['import_milestone Grant Klinik', 'hospital_owner_granted Grant Klinik', 'hospital_associated Grant Klinik'], $this->items($page));
        self::assertSame(5, $page->items[0]->milestone);
        self::assertSame(
            ['bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'project-feed-meta'],
            [$page->items[1]->hospitalPublicId, $page->items[1]->actorUsername],
        );
    }

    public function testMapsFirstImportNumericMilestoneAndIgnoresEmptyMetadata(): void
    {
        self::mockTime(self::NOW);
        $user = UserFactory::createOne(['username' => 'project-feed-first-import']);
        $userId = $this->id($user);
        $this->record($user, UserActivityType::FIRST_IMPORT, '2026-06-01 08:00:00', UserActivityDeduplicationKey::importMilestone($userId, 1), [
            'milestone' => '1',
            'hospitalName' => '',
            'hospitalPublicId' => '',
            'title' => '',
            'postTitle' => 'Fallback Title',
            'slug' => '',
            'postSlug' => 'fallback-slug',
        ]);
        $this->record($user, UserActivityType::IMPORT_MILESTONE, '2026-06-02 08:00:00', UserActivityDeduplicationKey::importMilestone($userId, 5), ['milestone' => 'not-a-number']);
        $this->flush();

        $page = $this->query()->getPage(null);

        self::assertCount(2, $page->items);
        self::assertSame([ProfileActivityType::IMPORT_MILESTONE, null], [$page->items[0]->type, $page->items[0]->milestone]);
        // Empty strings count as missing; "title" and "slug" fall back to "postTitle" and "postSlug".
        self::assertSame(
            [ProfileActivityType::FIRST_IMPORT, 1, null, null, 'Fallback Title', 'fallback-slug'],
            [$page->items[1]->type, $page->items[1]->milestone, $page->items[1]->hospitalName, $page->items[1]->hospitalPublicId, $page->items[1]->postTitle, $page->items[1]->postSlug],
        );
    }

    /**
     * @return iterable<string, array{ProjectActivityFilters, list<string>}>
     */
    public static function filters(): iterable
    {
        // Fixture: alice joined (1 Jan), published "Visible Title" (10 Mar) and commented (20 Mar); bob was given
        // access to Harbor Clinic (1 Apr).
        yield 'period' => [new ProjectActivityFilters(from: new \DateTimeImmutable('2026-03-01 00:00:00'), untilExclusive: new \DateTimeImmutable('2026-04-01 00:00:00')), ['comment_created filter-alice', 'post_published filter-alice']];
        yield 'type' => [new ProjectActivityFilters(type: UserActivityType::HOSPITAL_ASSOCIATED), ['hospital_associated filter-bob']];
        yield 'type outside the feed is ignored' => [new ProjectActivityFilters(type: UserActivityType::HOSPITAL_DISASSOCIATED), ['hospital_associated filter-bob', 'comment_created filter-alice', 'post_published filter-alice', 'joined filter-alice']];
        yield 'username in another case' => [new ProjectActivityFilters(username: 'Filter-Bob'), ['hospital_associated filter-bob']];
        // The free-text search also matches the display name (#1240).
        yield 'search: display name' => [new ProjectActivityFilters(search: 'bruno'), ['hospital_associated filter-bob']];
        yield 'search: post title' => [new ProjectActivityFilters(search: 'visible title'), ['post_published filter-alice']];
        yield 'search: hospital name' => [new ProjectActivityFilters(search: 'harbor'), ['hospital_associated filter-bob']];
        yield 'search: the slug does not match' => [new ProjectActivityFilters(search: 'secret-slug-token'), []];
        yield 'search: comment excerpt with other filters' => [new ProjectActivityFilters(from: new \DateTimeImmutable('2026-03-01 00:00:00'), type: UserActivityType::COMMENT_CREATED, username: 'filter-alice', search: 'clinic'), ['comment_created filter-alice']];
    }

    /**
     * @param list<string> $expected type and actor per item
     */
    #[DataProvider('filters')]
    public function testFiltersAtQueryLevel(ProjectActivityFilters $filters, array $expected): void
    {
        self::mockTime(self::NOW);
        $alice = UserFactory::createOne(['username' => 'filter-alice']);
        $bob = UserFactory::createOne(['username' => 'filter-bob', 'displayName' => 'Bruno Harborman']);
        $aliceId = $this->id($alice);
        $this->record($alice, UserActivityType::POST_PUBLISHED, '2026-03-10 12:00:00', UserActivityDeduplicationKey::postPublished($aliceId, 21), ['title' => 'Visible Title', 'slug' => 'secret-slug-token', 'postId' => 21]);
        $this->record($alice, UserActivityType::COMMENT_CREATED, '2026-03-20 12:00:00', UserActivityDeduplicationKey::commentCreated($aliceId, 22), ['postTitle' => 'Other Post', 'excerpt' => 'Nice clinic note', 'postSlug' => 'other-post']);
        $this->record($bob, UserActivityType::HOSPITAL_ASSOCIATED, '2026-04-01 12:00:00', UserActivityDeduplicationKey::hospitalAssociated($this->id($bob), 3, 9), ['hospitalName' => 'Harbor Clinic']);
        $this->record($alice, UserActivityType::JOINED, '2026-01-01 12:00:00', UserActivityDeduplicationKey::joined($aliceId));
        $this->flush();

        $page = $this->query()->getPage(null, 20, $filters);

        self::assertSame($expected, array_map(
            static fn (ProjectActivity $item): string => $item->type->value.' '.$item->actorUsername,
            $page->items,
        ));
    }

    public function testFilteredFirstPageStopsAtTheLimit(): void
    {
        self::mockTime(self::NOW);
        $this->fivePostsAndJoined();

        $page = $this->query()->getPage(null, 3, new ProjectActivityFilters(type: UserActivityType::POST_PUBLISHED));

        self::assertSame(['post_published Paged Filter 5', 'post_published Paged Filter 4', 'post_published Paged Filter 3'], $this->items($page));
        self::assertTrue($page->hasMore());
    }

    public function testFilteredNextPageKeepsTheFilterWithoutGapsOrRepeats(): void
    {
        self::mockTime(self::NOW);
        $this->fivePostsAndJoined();
        $filters = new ProjectActivityFilters(type: UserActivityType::POST_PUBLISHED);
        $first = $this->query()->getPage(null, 3, $filters);

        $second = $this->query()->getPage($first->nextCursor, 3, $filters);

        // The joined activity of 10 July is newer than every post, but not of the filtered type.
        self::assertSame(['post_published Paged Filter 2', 'post_published Paged Filter 1'], $this->items($second));
        self::assertFalse($second->hasMore());
    }

    public function testHidesPostsPublishedAfterNow(): void
    {
        self::mockTime('2026-05-15 10:00:00');
        $user = UserFactory::createOne(['username' => 'project-feed-scheduled']);
        $userId = $this->id($user);
        $this->record($user, UserActivityType::POST_PUBLISHED, '2026-05-15 09:00:00', UserActivityDeduplicationKey::postPublished($userId, 401), ['title' => 'Already Live', 'slug' => 'already-live']);
        // Exactly now counts as published.
        $this->record($user, UserActivityType::POST_PUBLISHED, '2026-05-15 10:00:00', UserActivityDeduplicationKey::postPublished($userId, 402), ['title' => 'Publishing Now', 'slug' => 'publishing-now']);
        $this->record($user, UserActivityType::POST_PUBLISHED, '2026-05-15 10:00:01', UserActivityDeduplicationKey::postPublished($userId, 403), ['title' => 'Scheduled Headline', 'slug' => 'scheduled-headline']);
        // Only posts wait for their time; other activity in the future is shown.
        $this->record($user, UserActivityType::HOSPITAL_ASSOCIATED, '2026-05-17 10:00:00', UserActivityDeduplicationKey::hospitalAssociated($userId, 8, 15), ['hospitalName' => 'Future Clinic']);
        $this->flush();

        $page = $this->query()->getPage(null, 20);

        self::assertSame(['hospital_associated Future Clinic', 'post_published Publishing Now', 'post_published Already Live'], $this->items($page));
    }

    public function testHospitalFilterShowsAllHospitalTypesOfThatHospital(): void
    {
        self::mockTime(self::NOW);
        $this->sixActivitiesOfKlinikAAndTwoOthers();

        $page = $this->query()->getPage(null, 4, new ProjectActivityFilters(hospitalPublicId: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'));

        self::assertSame(['hospital_disassociated Klinik A', 'hospital_owner_revoked Klinik A', 'import_milestone Klinik A', 'first_import Klinik A'], $this->items($page));
        self::assertNotNull($page->nextCursor);
    }

    public function testHospitalFilterNextPageStaysOnThatHospital(): void
    {
        self::mockTime(self::NOW);
        $this->sixActivitiesOfKlinikAAndTwoOthers();
        $filters = new ProjectActivityFilters(hospitalPublicId: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
        $first = $this->query()->getPage(null, 4, $filters);

        $second = $this->query()->getPage($first->nextCursor, 4, $filters);

        self::assertSame(['hospital_owner_granted Klinik A', 'hospital_associated Klinik A'], $this->items($second));
        self::assertNull($second->nextCursor);
    }

    public function testWithoutTheHospitalFilterRemovedAccessAndOwnershipStayHidden(): void
    {
        self::mockTime(self::NOW);
        $this->sixActivitiesOfKlinikAAndTwoOthers();

        $page = $this->query()->getPage(null, 20);

        self::assertSame([
            'joined',
            'hospital_associated Klinik B',
            'import_milestone Klinik A',
            'first_import Klinik A',
            'hospital_owner_granted Klinik A',
            'hospital_associated Klinik A',
        ], $this->items($page));
    }

    /**
     * alice: joined 1 Jan, "Post 1" to "Post 12" on 1 to 12 Feb; bob: access removed 1 Mar, ownership revoked 2 Mar.
     */
    private function twelvePostsAndRemovedAccess(): void
    {
        $alice = UserFactory::createOne(['username' => 'project-feed-alice']);
        $bob = UserFactory::createOne(['username' => 'project-feed-bob']);
        $aliceId = $this->id($alice);
        $bobId = $this->id($bob);
        $this->record($alice, UserActivityType::JOINED, '2026-01-01 10:00:00', UserActivityDeduplicationKey::joined($aliceId));
        $this->record($bob, UserActivityType::HOSPITAL_DISASSOCIATED, '2026-03-01 10:00:00', UserActivityDeduplicationKey::hospitalDisassociated($bobId, 1, 1));
        $this->record($bob, UserActivityType::HOSPITAL_OWNER_REVOKED, '2026-03-02 10:00:00', UserActivityDeduplicationKey::hospitalOwnerRevoked($bobId, 1));
        for ($i = 1; $i <= 12; ++$i) {
            $this->record($alice, UserActivityType::POST_PUBLISHED, \sprintf('2026-02-%02d 12:00:00', $i), UserActivityDeduplicationKey::postPublished($aliceId, $i), ['title' => 'Post '.$i, 'slug' => 'post-'.$i, 'postId' => $i]);
        }
        $this->flush();
    }

    /**
     * "Paged Filter 1" to "Paged Filter 5" on 1 to 5 July, joined on 10 July.
     */
    private function fivePostsAndJoined(): void
    {
        $user = UserFactory::createOne(['username' => 'filter-pager']);
        $userId = $this->id($user);
        for ($i = 1; $i <= 5; ++$i) {
            $this->record($user, UserActivityType::POST_PUBLISHED, \sprintf('2026-07-%02d 12:00:00', $i), UserActivityDeduplicationKey::postPublished($userId, 100 + $i), ['title' => 'Paged Filter '.$i]);
        }
        $this->record($user, UserActivityType::JOINED, '2026-07-10 12:00:00', UserActivityDeduplicationKey::joined($userId));
        $this->flush();
    }

    /**
     * Klinik A: one activity per hospital type, 1st to 6th of June. Not Klinik A: access to Klinik B (7 June) and
     * joined, without a hospital (8 June).
     */
    private function sixActivitiesOfKlinikAAndTwoOthers(): void
    {
        $user = UserFactory::createOne(['username' => 'hospital-feed']);
        $userId = $this->id($user);
        $here = ['hospitalName' => 'Klinik A', 'hospitalPublicId' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'];
        $elsewhere = ['hospitalName' => 'Klinik B', 'hospitalPublicId' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'];
        $this->record($user, UserActivityType::HOSPITAL_ASSOCIATED, '2026-06-01 08:00:00', UserActivityDeduplicationKey::hospitalAssociated($userId, 1, 1), $here);
        $this->record($user, UserActivityType::HOSPITAL_OWNER_GRANTED, '2026-06-02 08:00:00', UserActivityDeduplicationKey::hospitalOwnerGranted($userId, 1), $here);
        $this->record($user, UserActivityType::FIRST_IMPORT, '2026-06-03 08:00:00', UserActivityDeduplicationKey::importMilestone($userId, 1), ['milestone' => 1] + $here);
        $this->record($user, UserActivityType::IMPORT_MILESTONE, '2026-06-04 08:00:00', UserActivityDeduplicationKey::importMilestone($userId, 10), ['milestone' => 10] + $here);
        $this->record($user, UserActivityType::HOSPITAL_OWNER_REVOKED, '2026-06-05 08:00:00', UserActivityDeduplicationKey::hospitalOwnerRevoked($userId, 1), $here);
        $this->record($user, UserActivityType::HOSPITAL_DISASSOCIATED, '2026-06-06 08:00:00', UserActivityDeduplicationKey::hospitalDisassociated($userId, 1, 1), $here);
        $this->record($user, UserActivityType::HOSPITAL_ASSOCIATED, '2026-06-07 08:00:00', UserActivityDeduplicationKey::hospitalAssociated($userId, 2, 2), $elsewhere);
        $this->record($user, UserActivityType::JOINED, '2026-06-08 08:00:00', UserActivityDeduplicationKey::joined($userId));
        $this->flush();
    }

    private function query(): ProjectActivityQuery
    {
        return self::getContainer()->get(ProjectActivityQuery::class);
    }

    /**
     * @return list<string> type and the post title or hospital name, if any, per item
     */
    private function items(ProjectActivityPage $page): array
    {
        return array_map(
            static fn (ProjectActivity $item): string => trim($item->type->value.' '.($item->postTitle ?? $item->hospitalName ?? '')),
            $page->items,
        );
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
