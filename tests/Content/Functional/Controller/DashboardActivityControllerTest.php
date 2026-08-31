<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Controller;

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
        $client->request(Request::METHOD_GET, '/dashboard/activity');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="dashboard-activity-item"][data-activity-type="post_published"]');
        self::assertSelectorTextContains('body', 'activity-actor');
        self::assertSelectorTextContains('body', 'Hello Post');
        self::assertSelectorTextContains('body', 'Plain Clinic');
        self::assertSelectorNotExists('a[href^="/explore/user/"]');
        self::assertSelectorNotExists('a[href^="/explore/hospital/"]');
        self::assertSelectorTextNotContains('body', 'Hidden Clinic');
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
