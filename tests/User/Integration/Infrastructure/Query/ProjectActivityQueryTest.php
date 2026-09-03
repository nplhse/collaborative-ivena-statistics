<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Query;

use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Activity\UserActivityDeduplicationKey;
use App\User\Application\Explore\ProfileActivityType;
use App\User\Application\Explore\ProjectActivityFilters;
use App\User\Application\Explore\ProjectActivityPage;
use App\User\Domain\Entity\User;
use App\User\Domain\Entity\UserActivity;
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Query\ProjectActivityQuery;
use Doctrine\ORM\EntityManagerInterface;

final class ProjectActivityQueryTest extends DatabaseKernelTestCase
{
    public function testOrdersByOccurredAtAndPaginatesWithoutDuplicates(): void
    {
        $alice = UserFactory::createOne(['username' => 'project-feed-alice', 'isEnabled' => true]);
        $bob = UserFactory::createOne(['username' => 'project-feed-bob', 'isEnabled' => true]);
        $aliceId = $alice->getId();
        $bobId = $bob->getId();
        self::assertNotNull($aliceId);
        self::assertNotNull($bobId);

        $this->record($alice, UserActivityType::JOINED, new \DateTimeImmutable('2026-01-01 10:00:00'), UserActivityDeduplicationKey::joined($aliceId));
        $this->record($bob, UserActivityType::HOSPITAL_DISASSOCIATED, new \DateTimeImmutable('2026-03-01 10:00:00'), UserActivityDeduplicationKey::hospitalDisassociated($bobId, 1, 1));
        $this->record($bob, UserActivityType::HOSPITAL_OWNER_REVOKED, new \DateTimeImmutable('2026-03-02 10:00:00'), UserActivityDeduplicationKey::hospitalOwnerRevoked($bobId, 1));

        for ($i = 1; $i <= 12; ++$i) {
            $this->record(
                $alice,
                UserActivityType::POST_PUBLISHED,
                new \DateTimeImmutable(sprintf('2026-02-%02d 12:00:00', $i)),
                UserActivityDeduplicationKey::postPublished($aliceId, $i),
                ['title' => 'Post '.$i, 'slug' => 'post-'.$i, 'postId' => $i],
            );
        }

        $query = self::getContainer()->get(ProjectActivityQuery::class);
        $first = $query->getPage(null, ProjectActivityPage::PAGE_SIZE);
        self::assertCount(10, $first->items);
        self::assertTrue($first->hasMore());
        self::assertNotNull($first->nextCursor);
        self::assertSame(ProfileActivityType::POST_PUBLISHED, $first->items[0]->type);
        self::assertSame('project-feed-alice', $first->items[0]->actorUsername);
        self::assertSame('Post 12', $first->items[0]->postTitle);

        $types = array_map(static fn ($item) => $item->type, $first->items);
        self::assertNotContains(ProfileActivityType::HOSPITAL_DISASSOCIATED, $types);
        self::assertNotContains(ProfileActivityType::HOSPITAL_OWNER_REVOKED, $types);

        $second = $query->getPage($first->nextCursor, ProjectActivityPage::PAGE_SIZE);
        $allKeys = [
            ...array_map(static fn ($activity): string => $activity->type->value.':'.$activity->stableId, $first->items),
            ...array_map(static fn ($activity): string => $activity->type->value.':'.$activity->stableId, $second->items),
        ];

        self::assertCount(3, $second->items);
        self::assertCount(13, $allKeys);
        self::assertCount(13, array_values(array_unique($allKeys)));
        self::assertFalse($second->hasMore());
        self::assertSame(ProfileActivityType::JOINED, $second->items[array_key_last($second->items)]->type);
    }

    public function testSkipsDisabledUsersAndExcludedTypes(): void
    {
        $enabled = UserFactory::createOne(['username' => 'project-feed-enabled']);
        $disabled = UserFactory::createOne(['username' => 'project-feed-disabled', 'isEnabled' => false]);
        $enabledId = $enabled->getId();
        $disabledId = $disabled->getId();
        self::assertNotNull($enabledId);
        self::assertNotNull($disabledId);

        $this->record(
            $enabled,
            UserActivityType::COMMENT_CREATED,
            new \DateTimeImmutable('2026-04-01 09:00:00'),
            UserActivityDeduplicationKey::commentCreated($enabledId, 3),
            ['postTitle' => 'Artikel', 'postSlug' => 'artikel', 'excerpt' => 'Hi'],
        );
        $this->record(
            $disabled,
            UserActivityType::POST_PUBLISHED,
            new \DateTimeImmutable('2026-04-02 09:00:00'),
            UserActivityDeduplicationKey::postPublished($disabledId, 99),
            ['title' => 'Hidden', 'slug' => 'hidden'],
        );
        $this->record(
            $enabled,
            UserActivityType::HOSPITAL_DISASSOCIATED,
            new \DateTimeImmutable('2026-04-03 09:00:00'),
            UserActivityDeduplicationKey::hospitalDisassociated($enabledId, 4, 8),
            ['hospitalName' => 'Secret'],
        );

        $page = self::getContainer()->get(ProjectActivityQuery::class)->getPage(null);
        self::assertCount(1, $page->items);
        self::assertSame(ProfileActivityType::COMMENT_CREATED, $page->items[0]->type);
        self::assertSame('project-feed-enabled', $page->items[0]->actorUsername);
        self::assertSame('Hi', $page->items[0]->excerpt);
    }

    public function testMapsHospitalAndMilestoneMetadataAndIgnoresEmptyLimit(): void
    {
        $user = UserFactory::createOne(['username' => 'project-feed-meta']);
        $userId = $user->getId();
        self::assertNotNull($userId);

        $this->record(
            $user,
            UserActivityType::HOSPITAL_ASSOCIATED,
            new \DateTimeImmutable('2026-05-01 08:00:00'),
            UserActivityDeduplicationKey::hospitalAssociated($userId, 12, 41),
            ['hospitalName' => 'Grant Klinik', 'hospitalPublicId' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'],
        );
        $this->record(
            $user,
            UserActivityType::HOSPITAL_OWNER_GRANTED,
            new \DateTimeImmutable('2026-05-02 08:00:00'),
            UserActivityDeduplicationKey::hospitalOwnerGranted($userId, 12),
            ['hospitalName' => 'Grant Klinik', 'hospitalPublicId' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'],
        );
        $this->record(
            $user,
            UserActivityType::IMPORT_MILESTONE,
            new \DateTimeImmutable('2026-05-03 08:00:00'),
            UserActivityDeduplicationKey::importMilestone($userId, 5),
            ['milestone' => 5, 'hospitalName' => 'Grant Klinik', 'hospitalPublicId' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'],
        );

        $query = self::getContainer()->get(ProjectActivityQuery::class);
        $empty = $query->getPage(null, 0);
        self::assertSame([], $empty->items);
        self::assertNull($empty->nextCursor);

        $page = $query->getPage(null);
        self::assertCount(3, $page->items);
        self::assertSame(ProfileActivityType::IMPORT_MILESTONE, $page->items[0]->type);
        self::assertSame(5, $page->items[0]->milestone);
        self::assertSame(ProfileActivityType::HOSPITAL_OWNER_GRANTED, $page->items[1]->type);
        self::assertSame('Grant Klinik', $page->items[1]->hospitalName);
        self::assertSame('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', $page->items[1]->hospitalPublicId);
        self::assertNotNull($page->items[1]->actorPublicId);
        self::assertSame(ProfileActivityType::HOSPITAL_ASSOCIATED, $page->items[2]->type);
    }

    public function testMapsFirstImportNumericMilestoneAndIgnoresEmptyMetadata(): void
    {
        $user = UserFactory::createOne(['username' => 'project-feed-first-import']);
        $userId = $user->getId();
        self::assertNotNull($userId);

        $this->record(
            $user,
            UserActivityType::FIRST_IMPORT,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            UserActivityDeduplicationKey::importMilestone($userId, 1),
            [
                'milestone' => '1',
                'hospitalName' => '',
                'hospitalPublicId' => '',
                'title' => '',
                'postTitle' => 'Fallback Title',
                'slug' => '',
                'postSlug' => 'fallback-slug',
            ],
        );
        $this->record(
            $user,
            UserActivityType::IMPORT_MILESTONE,
            new \DateTimeImmutable('2026-06-02 08:00:00'),
            UserActivityDeduplicationKey::importMilestone($userId, 5),
            ['milestone' => 'not-a-number'],
        );

        $page = self::getContainer()->get(ProjectActivityQuery::class)->getPage(null);
        self::assertCount(2, $page->items);
        self::assertSame(ProfileActivityType::IMPORT_MILESTONE, $page->items[0]->type);
        self::assertNull($page->items[0]->milestone);
        self::assertSame(ProfileActivityType::FIRST_IMPORT, $page->items[1]->type);
        self::assertSame(1, $page->items[1]->milestone);
        self::assertNull($page->items[1]->hospitalName);
        self::assertNull($page->items[1]->hospitalPublicId);
        self::assertSame('Fallback Title', $page->items[1]->postTitle);
        self::assertSame('fallback-slug', $page->items[1]->postSlug);
    }

    public function testFiltersByPeriodTypeUserAndSearchAtQueryLevel(): void
    {
        $alice = UserFactory::createOne(['username' => 'filter-alice']);
        $bob = UserFactory::createOne(['username' => 'filter-bob']);
        $aliceId = $alice->getId();
        $bobId = $bob->getId();
        self::assertNotNull($aliceId);
        self::assertNotNull($bobId);

        $this->record(
            $alice,
            UserActivityType::POST_PUBLISHED,
            new \DateTimeImmutable('2026-03-10 12:00:00'),
            UserActivityDeduplicationKey::postPublished($aliceId, 21),
            ['title' => 'Visible Title', 'slug' => 'secret-slug-token', 'postId' => 21],
        );
        $this->record(
            $alice,
            UserActivityType::COMMENT_CREATED,
            new \DateTimeImmutable('2026-03-20 12:00:00'),
            UserActivityDeduplicationKey::commentCreated($aliceId, 22),
            ['postTitle' => 'Other Post', 'excerpt' => 'Nice clinic note', 'postSlug' => 'other-post'],
        );
        $this->record(
            $bob,
            UserActivityType::HOSPITAL_ASSOCIATED,
            new \DateTimeImmutable('2026-04-01 12:00:00'),
            UserActivityDeduplicationKey::hospitalAssociated($bobId, 3, 9),
            ['hospitalName' => 'Harbor Clinic'],
        );
        $this->record(
            $alice,
            UserActivityType::JOINED,
            new \DateTimeImmutable('2026-01-01 12:00:00'),
            UserActivityDeduplicationKey::joined($aliceId),
        );

        $query = self::getContainer()->get(ProjectActivityQuery::class);

        $byPeriod = $query->getPage(null, 20, new ProjectActivityFilters(
            from: new \DateTimeImmutable('2026-03-01 00:00:00'),
            untilExclusive: new \DateTimeImmutable('2026-04-01 00:00:00'),
        ));
        self::assertCount(2, $byPeriod->items);
        self::assertSame(ProfileActivityType::COMMENT_CREATED, $byPeriod->items[0]->type);
        self::assertSame(ProfileActivityType::POST_PUBLISHED, $byPeriod->items[1]->type);

        $byType = $query->getPage(null, 20, new ProjectActivityFilters(
            type: UserActivityType::HOSPITAL_ASSOCIATED,
        ));
        self::assertCount(1, $byType->items);
        self::assertSame('filter-bob', $byType->items[0]->actorUsername);

        $invalidType = $query->getPage(null, 20, new ProjectActivityFilters(
            type: UserActivityType::HOSPITAL_DISASSOCIATED,
        ));
        self::assertCount(4, $invalidType->items);

        $byUser = $query->getPage(null, 20, new ProjectActivityFilters(
            username: 'Filter-Bob',
        ));
        self::assertCount(1, $byUser->items);
        self::assertSame('Harbor Clinic', $byUser->items[0]->hospitalName);

        $byKeywordTitle = $query->getPage(null, 20, new ProjectActivityFilters(
            search: 'visible title',
        ));
        self::assertCount(1, $byKeywordTitle->items);
        self::assertSame('Visible Title', $byKeywordTitle->items[0]->postTitle);

        $byKeywordHospital = $query->getPage(null, 20, new ProjectActivityFilters(
            search: 'harbor',
        ));
        self::assertCount(1, $byKeywordHospital->items);
        self::assertSame(ProfileActivityType::HOSPITAL_ASSOCIATED, $byKeywordHospital->items[0]->type);

        $slugMustNotMatch = $query->getPage(null, 20, new ProjectActivityFilters(
            search: 'secret-slug-token',
        ));
        self::assertSame([], $slugMustNotMatch->items);

        $combined = $query->getPage(null, 20, new ProjectActivityFilters(
            from: new \DateTimeImmutable('2026-03-01 00:00:00'),
            type: UserActivityType::COMMENT_CREATED,
            username: 'filter-alice',
            search: 'clinic',
        ));
        self::assertCount(1, $combined->items);
        self::assertSame('Nice clinic note', $combined->items[0]->excerpt);
    }

    public function testPaginatesFilteredResultsWithoutDuplicates(): void
    {
        $user = UserFactory::createOne(['username' => 'filter-pager']);
        $userId = $user->getId();
        self::assertNotNull($userId);

        for ($i = 1; $i <= 5; ++$i) {
            $this->record(
                $user,
                UserActivityType::POST_PUBLISHED,
                new \DateTimeImmutable(sprintf('2026-07-%02d 12:00:00', $i)),
                UserActivityDeduplicationKey::postPublished($userId, 100 + $i),
                ['title' => 'Paged Filter '.$i],
            );
        }
        $this->record(
            $user,
            UserActivityType::JOINED,
            new \DateTimeImmutable('2026-07-10 12:00:00'),
            UserActivityDeduplicationKey::joined($userId),
        );

        $filters = new ProjectActivityFilters(
            type: UserActivityType::POST_PUBLISHED,
        );
        $query = self::getContainer()->get(ProjectActivityQuery::class);
        $first = $query->getPage(null, 3, $filters);
        self::assertCount(3, $first->items);
        self::assertTrue($first->hasMore());

        $second = $query->getPage($first->nextCursor, 3, $filters);
        $keys = [
            ...array_map(static fn ($activity): string => $activity->stableId, $first->items),
            ...array_map(static fn ($activity): string => $activity->stableId, $second->items),
        ];
        self::assertCount(2, $second->items);
        self::assertCount(5, $keys);
        self::assertCount(5, array_values(array_unique($keys)));
        self::assertFalse($second->hasMore());
        foreach ([...$first->items, ...$second->items] as $item) {
            self::assertSame(ProfileActivityType::POST_PUBLISHED, $item->type);
        }
    }

    public function testHidesScheduledPostPublishedUntilPublicationTime(): void
    {
        $user = UserFactory::createOne(['username' => 'project-feed-scheduled']);
        $userId = $user->getId();
        self::assertNotNull($userId);

        $this->record(
            $user,
            UserActivityType::POST_PUBLISHED,
            new \DateTimeImmutable('-1 hour'),
            UserActivityDeduplicationKey::postPublished($userId, 401),
            ['title' => 'Already Live', 'slug' => 'already-live'],
        );
        $this->record(
            $user,
            UserActivityType::POST_PUBLISHED,
            new \DateTimeImmutable('now'),
            UserActivityDeduplicationKey::postPublished($userId, 402),
            ['title' => 'Publishing Now', 'slug' => 'publishing-now'],
        );
        $this->record(
            $user,
            UserActivityType::POST_PUBLISHED,
            new \DateTimeImmutable('+1 day'),
            UserActivityDeduplicationKey::postPublished($userId, 403),
            ['title' => 'Scheduled Headline', 'slug' => 'scheduled-headline'],
        );
        $this->record(
            $user,
            UserActivityType::HOSPITAL_ASSOCIATED,
            new \DateTimeImmutable('+2 days'),
            UserActivityDeduplicationKey::hospitalAssociated($userId, 8, 15),
            ['hospitalName' => 'Future Clinic'],
        );

        $page = self::getContainer()->get(ProjectActivityQuery::class)->getPage(null, 20);
        $titles = array_map(static fn ($item) => $item->postTitle, $page->items);
        $types = array_map(static fn ($item) => $item->type, $page->items);

        self::assertContains('Already Live', $titles);
        self::assertContains('Publishing Now', $titles);
        self::assertNotContains('Scheduled Headline', $titles);
        self::assertContains(ProfileActivityType::HOSPITAL_ASSOCIATED, $types);
        self::assertSame('Future Clinic', $page->items[0]->hospitalName);
    }

    /**
     * @param array<string, scalar|null> $metadata
     */
    private function record(
        User $user,
        UserActivityType $type,
        \DateTimeImmutable $occurredAt,
        string $deduplicationKey,
        array $metadata = [],
    ): void {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new UserActivity(
            user: $user,
            type: $type,
            occurredAt: $occurredAt,
            deduplicationKey: $deduplicationKey,
            metadata: $metadata,
        ));
        $entityManager->flush();
    }
}
