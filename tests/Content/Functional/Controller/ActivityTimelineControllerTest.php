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
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ActivityTimelineControllerTest extends WebTestCase
{
    use Factories;

    public function testEmptyStateForAuthenticatedUser(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'timeline-empty-user']);
        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/activity');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="activity-timeline-empty"]');
        self::assertSelectorTextContains('body', 'No activity yet.');
        self::assertSelectorExists('[data-testid="footer-nav-activity"]');
        self::assertSelectorNotExists('header a[href="/activity"]');
        self::assertSelectorNotExists('[data-testid="nav-activity"]');
        self::assertSelectorExists('[data-testid="activity-timeline-filters"]');
        self::assertSelectorExists('.col-md-3 [data-testid="activity-timeline-filters"]');
        self::assertSelectorExists('.col-md-9 [data-testid="activity-timeline-feed"]');
        self::assertSelectorExists('[data-testid="activity-timeline-type-all"].active');
        self::assertSelectorNotExists('select[name="type"]');
    }

    public function testGuestIsDenied(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/activity');

        self::assertResponseRedirects();
    }

    public function testGuestIsDeniedFromFeed(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/activity/feed');

        self::assertResponseRedirects();
    }

    public function testShowsCommunityTypesAndHidesLinksForRoleUser(): void
    {
        $client = self::createClient();
        $viewer = UserFactory::createOne(['username' => 'timeline-viewer']);
        $actor = UserFactory::createOne(['username' => 'timeline-actor']);
        $actorId = $actor->getId();
        self::assertNotNull($actorId);

        $this->record(
            $actor,
            UserActivityType::POST_PUBLISHED,
            new \DateTimeImmutable('2026-05-01 12:00:00'),
            UserActivityDeduplicationKey::postPublished($actorId, 31),
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
        $crawler = $client->request(Request::METHOD_GET, '/activity');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="activity-timeline-item"][data-activity-type="post_published"]');
        self::assertSelectorTextContains('body', 'timeline-actor');
        self::assertSelectorTextContains('body', 'Hello Post');
        self::assertSelectorTextContains('body', 'Plain Clinic');
        self::assertSelectorNotExists('a[href^="/explore/user/"]');
        self::assertSelectorNotExists('a[href^="/explore/hospital/"]');
        self::assertSelectorTextNotContains('body', 'Hidden Clinic');
        self::assertSelectorExists('[data-testid="activity-timeline-item"] time[datetime]');
        self::assertNotEmpty($crawler->filter('[data-testid="activity-timeline-item"] time')->attr('title'));
        self::assertSame('0', $crawler->filter('[data-testid="activity-timeline-item"] time')->attr('tabindex'));
        self::assertDoesNotMatchRegularExpression(
            '/^\d{2}\.\d{2}\.\d{4}$/',
            trim($crawler->filter('[data-testid="activity-timeline-item"] time')->text()),
        );
    }

    public function testParticipantSeesProfileLinksAndCanPaginateWithFilters(): void
    {
        $client = self::createClient();
        $participant = UserFactory::createOne([
            'username' => 'timeline-participant',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $actor = UserFactory::createOne(['username' => 'timeline-pager']);
        $actorId = $actor->getId();
        self::assertNotNull($actorId);

        for ($i = 1; $i <= 11; ++$i) {
            $this->record(
                $actor,
                UserActivityType::POST_PUBLISHED,
                new \DateTimeImmutable(sprintf('2026-06-%02d 12:00:00', $i)),
                UserActivityDeduplicationKey::postPublished($actorId, 40 + $i),
                ['title' => 'Paged '.$i, 'slug' => 'paged-'.$i],
            );
        }
        $this->record(
            $actor,
            UserActivityType::JOINED,
            new \DateTimeImmutable('2026-06-20 12:00:00'),
            UserActivityDeduplicationKey::joined($actorId),
        );

        $client->loginUser($participant);
        $crawler = $client->request(Request::METHOD_GET, '/activity?type=post_published&user=timeline-pager');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="activity-timeline-type-post_published"].active');
        self::assertSelectorExists('a[href^="/explore/user/"]');
        self::assertCount(10, $crawler->filter('[data-testid="activity-timeline-item"]'));
        self::assertSelectorExists('[data-testid="activity-timeline-next"]');
        self::assertSelectorExists('turbo-frame[data-testid="activity-timeline-next"][loading="lazy"]');

        $nextSrc = $crawler->filter('[data-testid="activity-timeline-next"]')->attr('src');
        self::assertNotNull($nextSrc);
        self::assertStringContainsString('type=post_published', $nextSrc);
        self::assertStringContainsString('user=timeline-pager', $nextSrc);
        self::assertSelectorTextContains('[data-testid="activity-timeline-load-more"]', 'Show more activity');

        $frameId = $crawler->filter('[data-testid="activity-timeline-next"]')->attr('id');
        self::assertNotNull($frameId);

        $crawler = $client->request(
            Request::METHOD_GET,
            $nextSrc,
            [],
            [],
            ['HTTP_TURBO_FRAME' => $frameId],
        );
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="activity-timeline-item"]'));
        self::assertSelectorNotExists('[data-testid="activity-timeline-next"]');
        self::assertSelectorExists('[data-testid="activity-timeline-item"][data-activity-type="post_published"]');
    }

    public function testKeywordAndPeriodFiltersCombine(): void
    {
        $client = self::createClient();
        $viewer = UserFactory::createOne(['username' => 'timeline-filter-viewer']);
        $actor = UserFactory::createOne(['username' => 'timeline-filter-actor']);
        $actorId = $actor->getId();
        self::assertNotNull($actorId);

        $this->record(
            $actor,
            UserActivityType::POST_PUBLISHED,
            new \DateTimeImmutable('2026-03-10 12:00:00'),
            UserActivityDeduplicationKey::postPublished($actorId, 51),
            ['title' => 'Spring Report', 'slug' => 'ignored-slug-token'],
        );
        $this->record(
            $actor,
            UserActivityType::POST_PUBLISHED,
            new \DateTimeImmutable('2026-05-10 12:00:00'),
            UserActivityDeduplicationKey::postPublished($actorId, 52),
            ['title' => 'Later Report'],
        );

        $client->loginUser($viewer);
        $client->request(Request::METHOD_GET, '/activity?search=Spring&from=2026-03-01&until=2026-03-31');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="activity-timeline-item"]');
        self::assertSelectorTextContains('body', 'Spring Report');
        self::assertSelectorTextNotContains('body', 'Later Report');

        $client->request(Request::METHOD_GET, '/activity?search=ignored-slug-token');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="activity-timeline-empty"]');
        self::assertSelectorTextContains('body', 'No activities match the selected filters.');
    }

    public function testInvalidCursorFallsBackToFirstPage(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'timeline-cursor-fallback']);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $this->record(
            $user,
            UserActivityType::JOINED,
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            UserActivityDeduplicationKey::joined($userId),
        );

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/activity/feed?cursor=not-a-cursor');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="activity-timeline-item"][data-activity-type="joined"]');
    }

    public function testFeedFailureRendersErrorInsideTheFrame(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $user = UserFactory::createOne(['username' => 'timeline-error-user']);
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

        $client->request(Request::METHOD_GET, '/activity/feed');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="activity-timeline-error"]');
        self::assertSelectorTextContains('body', 'Activity could not be loaded.');
    }

    public function testPostPublishedPreviewShowsSanitizedLiveContentAndHidesUnpublished(): void
    {
        $client = self::createClient();
        $viewer = UserFactory::createOne(['username' => 'timeline-preview-viewer']);
        $actor = UserFactory::createOne(['username' => 'timeline-preview-actor']);
        $actorId = $actor->getId();
        self::assertNotNull($actorId);

        PostFactory::createOne([
            'createdBy' => $actor,
            'title' => 'Hello Post',
            'slug' => 'hello-post',
            'content' => '<p>Timeline intro</p><script>alert(1)</script><p>Rest</p>',
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

        $this->record(
            $actor,
            UserActivityType::POST_PUBLISHED,
            new \DateTimeImmutable('2026-05-01 12:00:00'),
            UserActivityDeduplicationKey::postPublished($actorId, 201),
            ['title' => 'Hello Post', 'slug' => 'hello-post'],
        );
        $this->record(
            $actor,
            UserActivityType::POST_PUBLISHED,
            new \DateTimeImmutable('2026-05-02 12:00:00'),
            UserActivityDeduplicationKey::postPublished($actorId, 202),
            ['title' => 'Draft Headline', 'slug' => 'draft-headline'],
        );

        $client->loginUser($viewer);
        $crawler = $client->request(Request::METHOD_GET, '/activity');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="activity-timeline-item"][data-activity-type="post_published"]');
        self::assertSelectorExists('[data-testid="activity-post-preview"]');
        self::assertSelectorTextContains('[data-testid="activity-post-preview"]', 'Timeline intro');
        self::assertSelectorTextNotContains('body', 'Secret draft body');
        self::assertSelectorTextNotContains('body', 'Rest');
        self::assertStringNotContainsString('<script>', $client->getResponse()->getContent() ?: '');
        self::assertSelectorExists('a[href="/blog/hello-post"]');
        self::assertCount(1, $crawler->filter('[data-testid="activity-post-preview"]'));

        $crawler = $client->request(Request::METHOD_GET, '/activity/feed');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="activity-post-preview"]');
        self::assertSelectorTextContains('[data-testid="activity-post-preview"]', 'Timeline intro');
        self::assertSelectorTextNotContains('body', 'Secret draft body');
        self::assertCount(1, $crawler->filter('[data-testid="activity-post-preview"]'));
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
