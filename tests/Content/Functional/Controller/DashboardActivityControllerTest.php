<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Controller;

use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Factory\PostFactory;
use App\User\Application\Activity\UserActivityDeduplicationKey;
use App\User\Application\Explore\ProjectActivityFilters;
use App\User\Application\Explore\ProjectActivityPage;
use App\User\Application\Explore\ProjectActivityQueryInterface;
use App\User\Domain\Entity\User;
use App\User\Domain\Entity\UserActivity;
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DashboardActivityControllerTest extends WebTestCase
{
    use Factories;

    public function testEmptyStateForAuthenticatedUser(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'activity-empty-user']);
        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/dashboard/activity');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="dashboard-activity-empty"]');
        self::assertSelectorTextContains('body', 'No recent activity yet.');
    }

    public function testGuestIsDenied(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/dashboard/activity');

        self::assertResponseRedirects();
    }

    public function testShowsCommunityTypesAndHidesLinksForRoleUser(): void
    {
        $client = self::createClient();
        $viewer = UserFactory::createOne(['username' => 'activity-viewer']);
        $actor = UserFactory::createOne(['username' => 'activity-actor']);
        $actorId = $actor->getId();
        self::assertNotNull($actorId);

        $this->record(
            $actor,
            UserActivityType::POST_PUBLISHED,
            new \DateTimeImmutable('2026-05-01 12:00:00'),
            UserActivityDeduplicationKey::postPublished($actorId, 1),
            ['title' => 'Hello Post', 'slug' => 'hello-post'],
        );
        $this->record(
            $actor,
            UserActivityType::HOSPITAL_ASSOCIATED,
            new \DateTimeImmutable('2026-05-03 12:00:00'),
            UserActivityDeduplicationKey::hospitalAssociated($actorId, 1, 1),
            [
                'hospitalName' => 'Plain Clinic',
                'hospitalPublicId' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            ],
        );
        $this->record(
            $actor,
            UserActivityType::HOSPITAL_DISASSOCIATED,
            new \DateTimeImmutable('2026-05-02 12:00:00'),
            UserActivityDeduplicationKey::hospitalDisassociated($actorId, 1, 1),
            ['hospitalName' => 'Hidden Clinic'],
        );

        $client->loginUser($viewer);
        $crawler = $client->request(Request::METHOD_GET, '/dashboard/activity');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="dashboard-activity-item"][data-activity-type="post_published"]');
        self::assertSelectorTextContains('body', 'activity-actor');
        self::assertSelectorTextContains('body', 'Hello Post');
        self::assertSelectorTextContains('body', 'Plain Clinic');
        self::assertSelectorNotExists('a[href^="/explore/user/"]');
        self::assertSelectorNotExists('a[href^="/explore/hospital/"]');
        self::assertSelectorTextNotContains('body', 'Hidden Clinic');
        $this->assertRelativeTimestampMarkup($crawler, 'dashboard-activity-item');
    }

    public function testParticipantSeesProfileLinksAndPreviewWithoutPagination(): void
    {
        $client = self::createClient();
        $participant = UserFactory::createOne([
            'username' => 'activity-participant',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $actor = UserFactory::createOne(['username' => 'activity-pager']);
        $actorId = $actor->getId();
        self::assertNotNull($actorId);

        for ($i = 1; $i <= 6; ++$i) {
            $this->record(
                $actor,
                UserActivityType::POST_PUBLISHED,
                new \DateTimeImmutable(sprintf('2026-06-%02d 12:00:00', $i)),
                UserActivityDeduplicationKey::postPublished($actorId, $i),
                ['title' => 'Paged '.$i, 'slug' => 'paged-'.$i],
            );
        }

        $client->loginUser($participant);
        $crawler = $client->request(Request::METHOD_GET, '/dashboard/activity');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href^="/explore/user/"]');
        self::assertCount(5, $crawler->filter('[data-testid="dashboard-activity-item"]'));
        self::assertSelectorNotExists('[data-testid="dashboard-activity-next"]');
        self::assertSelectorNotExists('[data-testid="dashboard-activity-load-more"]');
    }

    public function testPreviewIgnoresCursorQueryParameter(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'activity-cursor-fallback']);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $this->record(
            $user,
            UserActivityType::JOINED,
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            UserActivityDeduplicationKey::joined($userId),
        );

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/dashboard/activity?cursor=not-a-cursor');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="dashboard-activity-item"][data-activity-type="joined"]');
    }

    public function testParticipantSeesHospitalNameAsLink(): void
    {
        $client = self::createClient();
        $participant = UserFactory::createOne([
            'username' => 'activity-hospital-viewer',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $actor = UserFactory::createOne(['username' => 'activity-hospital-actor']);
        $actorId = $actor->getId();
        self::assertNotNull($actorId);
        $this->record(
            $actor,
            UserActivityType::HOSPITAL_ASSOCIATED,
            new \DateTimeImmutable('2026-07-01 09:00:00'),
            UserActivityDeduplicationKey::hospitalAssociated($actorId, 3, 7),
            [
                'hospitalName' => 'Linked Clinic',
                'hospitalPublicId' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            ],
        );

        $client->loginUser($participant);
        $client->request(Request::METHOD_GET, '/dashboard/activity');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/explore/hospital/cccccccc-cccc-4ccc-8ccc-cccccccccccc"]');
        self::assertSelectorTextContains('body', 'Linked Clinic');
    }

    public function testRendersRemainingCommunityTypesWithoutOptionalLinks(): void
    {
        $client = self::createClient();
        $viewer = UserFactory::createOne(['username' => 'activity-types-viewer']);
        $actor = UserFactory::createOne(['username' => 'activity-types-actor']);
        $actorId = $actor->getId();
        self::assertNotNull($actorId);

        $this->record(
            $actor,
            UserActivityType::FIRST_IMPORT,
            new \DateTimeImmutable('2026-08-01 09:00:00'),
            UserActivityDeduplicationKey::importMilestone($actorId, 1),
            ['milestone' => 1, 'hospitalName' => 'First Clinic'],
        );
        $this->record(
            $actor,
            UserActivityType::IMPORT_MILESTONE,
            new \DateTimeImmutable('2026-08-02 09:00:00'),
            UserActivityDeduplicationKey::importMilestone($actorId, 5),
            ['milestone' => 5, 'hospitalName' => 'First Clinic'],
        );
        $this->record(
            $actor,
            UserActivityType::COMMENT_CREATED,
            new \DateTimeImmutable('2026-08-03 09:00:00'),
            UserActivityDeduplicationKey::commentCreated($actorId, 11),
            ['postTitle' => 'Untitled Comment Post', 'excerpt' => 'Nice work'],
        );
        $this->record(
            $actor,
            UserActivityType::POST_PUBLISHED,
            new \DateTimeImmutable('2026-08-04 09:00:00'),
            UserActivityDeduplicationKey::postPublished($actorId, 12),
            ['title' => 'Draft Headline'],
        );

        $client->loginUser($viewer);
        $client->request(Request::METHOD_GET, '/dashboard/activity');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="dashboard-activity-item"][data-activity-type="first_import"]');
        self::assertSelectorExists('[data-testid="dashboard-activity-item"][data-activity-type="import_milestone"]');
        self::assertSelectorExists('[data-testid="dashboard-activity-item"][data-activity-type="comment_created"]');
        self::assertSelectorExists('[data-testid="dashboard-activity-item"][data-activity-type="post_published"]');
        self::assertSelectorTextContains('body', 'Imported first dataset');
        self::assertSelectorTextContains('body', 'Reached 5 data imports');
        self::assertSelectorTextContains('body', 'Wrote a comment');
        self::assertSelectorTextContains('body', 'On');
        self::assertSelectorTextContains('body', 'Untitled Comment Post');
        self::assertSelectorTextContains('body', 'Nice work');
        self::assertSelectorTextContains('body', 'Draft Headline');
        self::assertSelectorNotExists('a[href^="/blog/"]');
        self::assertSelectorNotExists('a[href^="/explore/hospital/"]');
    }

    public function testPostPublishedPreviewShowsSanitizedLiveContentAndHidesUnpublished(): void
    {
        $client = self::createClient();
        $viewer = UserFactory::createOne(['username' => 'activity-preview-viewer']);
        $actor = UserFactory::createOne(['username' => 'activity-preview-actor']);
        $actorId = $actor->getId();
        self::assertNotNull($actorId);

        PostFactory::createOne([
            'createdBy' => $actor,
            'title' => 'Hello Post',
            'slug' => 'hello-post',
            'content' => '<p>Preview paragraph</p><script>alert(1)</script><p>Rest</p>',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('2026-05-01 12:00:00'),
        ]);
        PostFactory::createOne([
            'createdBy' => $actor,
            'title' => 'Draft Headline',
            'slug' => 'draft-headline',
            'content' => '<p>Secret draft body</p>',
            'status' => PostStatus::DRAFT,
            'publishedAt' => null,
        ]);
        PostFactory::createOne([
            'createdBy' => $actor,
            'title' => 'Empty Live',
            'slug' => 'empty-live',
            'content' => '',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('2026-05-02 12:00:00'),
        ]);

        $this->record(
            $actor,
            UserActivityType::POST_PUBLISHED,
            new \DateTimeImmutable('2026-05-01 12:00:00'),
            UserActivityDeduplicationKey::postPublished($actorId, 101),
            ['title' => 'Hello Post', 'slug' => 'hello-post'],
        );
        $this->record(
            $actor,
            UserActivityType::POST_PUBLISHED,
            new \DateTimeImmutable('2026-05-02 12:00:00'),
            UserActivityDeduplicationKey::postPublished($actorId, 102),
            ['title' => 'Draft Headline', 'slug' => 'draft-headline'],
        );
        $this->record(
            $actor,
            UserActivityType::POST_PUBLISHED,
            new \DateTimeImmutable('2026-05-03 12:00:00'),
            UserActivityDeduplicationKey::postPublished($actorId, 103),
            ['title' => 'Empty Live', 'slug' => 'empty-live'],
        );

        $client->loginUser($viewer);
        $crawler = $client->request(Request::METHOD_GET, '/dashboard/activity');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="activity-post-preview"]');
        self::assertSelectorTextContains('[data-testid="activity-post-preview"]', 'Preview paragraph');
        self::assertSelectorTextNotContains('body', 'Secret draft body');
        self::assertSelectorTextNotContains('body', 'Rest');
        self::assertStringNotContainsString('<script>', $client->getResponse()->getContent() ?: '');
        self::assertSelectorExists('a[href="/blog/hello-post"]');
        self::assertCount(1, $crawler->filter('[data-testid="activity-post-preview"]'));
    }

    public function testHidesScheduledBlogPostUntilPublicationDate(): void
    {
        $client = self::createClient();
        $viewer = UserFactory::createOne(['username' => 'activity-scheduled-viewer']);
        $actor = UserFactory::createOne(['username' => 'activity-scheduled-actor']);
        $actorId = $actor->getId();
        self::assertNotNull($actorId);

        PostFactory::createOne([
            'createdBy' => $actor,
            'title' => 'Live Dashboard Post',
            'slug' => 'live-dashboard-post',
            'content' => '<p>Already public</p>',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 hour'),
        ]);
        PostFactory::createOne([
            'createdBy' => $actor,
            'title' => 'Scheduled Dashboard Post',
            'slug' => 'scheduled-dashboard-post',
            'content' => '<p>Not public yet</p>',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('+1 day'),
        ]);

        $this->record(
            $actor,
            UserActivityType::POST_PUBLISHED,
            new \DateTimeImmutable('-1 hour'),
            UserActivityDeduplicationKey::postPublished($actorId, 401),
            ['title' => 'Live Dashboard Post', 'slug' => 'live-dashboard-post'],
        );
        $this->record(
            $actor,
            UserActivityType::POST_PUBLISHED,
            new \DateTimeImmutable('+1 day'),
            UserActivityDeduplicationKey::postPublished($actorId, 402),
            ['title' => 'Scheduled Dashboard Post', 'slug' => 'scheduled-dashboard-post'],
        );

        $client->loginUser($viewer);
        $client->request(Request::METHOD_GET, '/dashboard/activity');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Live Dashboard Post');
        self::assertSelectorExists('a[href="/blog/live-dashboard-post"]');
        self::assertSelectorTextNotContains('body', 'Scheduled Dashboard Post');
        self::assertSelectorTextNotContains('body', 'Not public yet');
        self::assertSelectorNotExists('a[href="/blog/scheduled-dashboard-post"]');
    }

    public function testFeedFailureRendersErrorInsideTheFrame(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $user = UserFactory::createOne(['username' => 'activity-error-user']);
        $client->loginUser($user);

        self::getContainer()->set(ProjectActivityQueryInterface::class, new class implements ProjectActivityQueryInterface {
            public function getPage(
                ?string $cursor,
                int $limit = ProjectActivityPage::PAGE_SIZE,
                ?ProjectActivityFilters $filters = null,
            ): ProjectActivityPage {
                throw new \RuntimeException('activity query failed');
            }
        });

        $client->request(Request::METHOD_GET, '/dashboard/activity');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="dashboard-activity-error"]');
        self::assertSelectorTextContains('body', 'Activity could not be loaded.');
    }

    public function testRecentActivityUsesJustNowLabel(): void
    {
        $client = self::createClient();
        $viewer = UserFactory::createOne(['username' => 'activity-just-now-viewer']);
        $actor = UserFactory::createOne(['username' => 'activity-just-now-actor']);
        $actorId = $actor->getId();
        self::assertNotNull($actorId);

        $this->record(
            $actor,
            UserActivityType::POST_PUBLISHED,
            new \DateTimeImmutable('-20 seconds'),
            UserActivityDeduplicationKey::postPublished($actorId, 201),
            ['title' => 'Just Now Post', 'slug' => 'just-now-post'],
        );

        $client->loginUser($viewer);
        $client->request(Request::METHOD_GET, '/dashboard/activity');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="dashboard-activity-item"] time', 'just now');
    }

    private function assertRelativeTimestampMarkup(Crawler $crawler, string $itemTestId): void
    {
        $time = $crawler->filter(sprintf('[data-testid="%s"] time', $itemTestId))->first();
        self::assertGreaterThan(0, $time->count());
        self::assertNotEmpty($time->attr('datetime'));
        self::assertNotEmpty($time->attr('title'));
        self::assertSame('0', $time->attr('tabindex'));
        self::assertNotEmpty($time->attr('aria-describedby'));
        self::assertDoesNotMatchRegularExpression('/^\d{2}\.\d{2}\.\d{4}$/', trim($time->text()));
        self::assertGreaterThan(0, $crawler->filter(sprintf('[data-testid="%s"] .visually-hidden', $itemTestId))->count());
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
