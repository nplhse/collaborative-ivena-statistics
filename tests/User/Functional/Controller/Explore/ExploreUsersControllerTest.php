<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller\Explore;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Factory\PostCommentFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Activity\UserActivityBackfill;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ExploreUsersControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testListRedirectsGuestsToLogin(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/explore/user');

        self::assertResponseRedirects('/login');
    }

    public function testListIsForbiddenForNonParticipant(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/explore/user');

        self::assertResponseStatusCodeSame(403);
    }

    public function testListShowsUsersForParticipantWithoutExposingEmail(): void
    {
        $client = $this->createClientAsParticipant();

        $listed = UserFactory::createOne([
            'username' => 'alpha-user',
            'email' => 'alpha-secret@example.test',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
        ]);

        $client->request(Request::METHOD_GET, '/explore/user');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="user-directory-list"]', 'alpha-user');
        self::assertSelectorExists('[data-testid="user-badge-participant"]');
        self::assertStringNotContainsString((string) $listed->getEmail(), $client->getResponse()->getContent() ?: '');
        self::assertStringNotContainsString('alpha-secret@example.test', $client->getResponse()->getContent() ?: '');
    }

    public function testUserOnlyRoleShowsUserBadgeOnListAndProfile(): void
    {
        $client = $this->createClientAsParticipant();

        $listed = UserFactory::createOne([
            'username' => 'plain-user',
            'roles' => [UserRole::USER],
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/user');
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-testid="user-directory-row"]')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'plain-user'),
        );
        self::assertCount(1, $row);
        self::assertCount(1, $row->filter('[data-testid="user-badge-user"]'));
        self::assertCount(0, $row->filter('[data-testid="user-badge-participant"]'));

        $client->request(Request::METHOD_GET, '/explore/user/'.$listed->getPublicIdString());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="user-badge-user"]');
        self::assertSelectorNotExists('[data-testid="user-badge-participant"]');
        self::assertSelectorNotExists('[data-testid="user-badge-admin"]');
    }

    public function testListIsSortedAlphabeticallyByUsernameAscendingByDefault(): void
    {
        $client = $this->createClientAsParticipant();

        UserFactory::createOne([
            'username' => 'zeta-user',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
        ]);
        UserFactory::createOne([
            'username' => 'Alpha-User',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
        ]);
        UserFactory::createOne([
            'username' => 'middle-user',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/user');

        self::assertResponseIsSuccessful();

        $usernames = $crawler->filter('[data-testid="user-directory-row"] a')->each(
            static fn ($node): string => trim($node->text()),
        );
        $relevant = array_values(array_filter(
            $usernames,
            static fn (string $username): bool => \in_array($username, ['Alpha-User', 'middle-user', 'zeta-user'], true),
        ));

        self::assertSame(['Alpha-User', 'middle-user', 'zeta-user'], $relevant);
    }

    public function testListCanBeSortedByCreatedAt(): void
    {
        $client = $this->createClientAsParticipant();

        UserFactory::createOne([
            'username' => 'newest-user',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
            'createdAt' => new \DateTimeImmutable('2026-08-10 12:00:00'),
        ]);
        UserFactory::createOne([
            'username' => 'oldest-user',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
            'createdAt' => new \DateTimeImmutable('2024-01-05 08:00:00'),
        ]);
        UserFactory::createOne([
            'username' => 'middle-created-user',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
            'createdAt' => new \DateTimeImmutable('2025-06-01 09:00:00'),
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/user', [
            'sortBy' => 'createdAt',
            'orderBy' => 'asc',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="user-directory-list"]', '05.01.2024');

        $usernames = $crawler->filter('[data-testid="user-directory-row"] a[href^="/explore/user/"]')->each(
            static fn ($node): string => trim($node->text()),
        );
        $relevant = array_values(array_filter(
            $usernames,
            static fn (string $username): bool => \in_array(
                $username,
                ['oldest-user', 'middle-created-user', 'newest-user'],
                true,
            ),
        ));

        self::assertSame(['oldest-user', 'middle-created-user', 'newest-user'], $relevant);
    }

    public function testListSearchByUsername(): void
    {
        $client = $this->createClientAsParticipant();

        UserFactory::createOne([
            'username' => 'searchable-user',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
        ]);
        UserFactory::createOne([
            'username' => 'other-user',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
        ]);

        $client->request(Request::METHOD_GET, '/explore/user', ['search' => 'searchable']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="user-directory-list"]', 'searchable-user');
        self::assertSelectorTextNotContains('[data-testid="user-directory-list"]', 'other-user');
    }

    public function testListFiltersByParticipantAndBoardMember(): void
    {
        $client = $this->createClientAsParticipant();

        UserFactory::createOne([
            'username' => 'plain-user-only',
            'roles' => [UserRole::USER],
        ]);
        UserFactory::createOne([
            'username' => 'board-only-user',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::BOARD_MEMBER],
        ]);

        $client->request(Request::METHOD_GET, '/explore/user', ['participant' => '1']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="user-directory-active-filters"]');
        self::assertSelectorTextContains('[data-testid="user-directory-list"]', 'board-only-user');
        self::assertSelectorTextNotContains('[data-testid="user-directory-list"]', 'plain-user-only');

        $client->request(Request::METHOD_GET, '/explore/user', ['boardMember' => '1']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="user-directory-filters"]');
        self::assertSelectorExists('[data-testid="user-directory-active-filter-count"]');
        self::assertSelectorTextContains('[data-testid="user-directory-list"]', 'board-only-user');
        self::assertSelectorExists('[data-testid="user-badge-board-member"]');
        self::assertSelectorTextNotContains('[data-testid="user-directory-list"]', 'plain-user-only');
    }

    public function testListFiltersByHospital(): void
    {
        $client = $this->createClientAsParticipant();

        $owner = UserFactory::createOne([
            'username' => 'hospital-owner-user',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
        ]);
        $other = UserFactory::createOne([
            'username' => 'unrelated-user',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
        ]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Filter Klinik',
            'owner' => $owner,
        ]);

        $client->request(Request::METHOD_GET, '/explore/user', ['hospitalId' => (string) $hospital->getId()]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="user-directory-list"]', 'hospital-owner-user');
        self::assertSelectorTextNotContains('[data-testid="user-directory-list"]', 'unrelated-user');
        unset($other);
    }

    public function testShowProfileWithHospitalsImportsPostsAndBadges(): void
    {
        $client = self::createClient();
        $viewer = $this->loginAsParticipant($client);

        $profileUser = UserFactory::createOne([
            'username' => 'profile-user',
            'email' => 'profile-secret@example.test',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::BOARD_MEMBER],
            'createdAt' => new \DateTimeImmutable('2025-03-15 10:30:00'),
            'updatedAt' => new \DateTimeImmutable('2026-08-01 14:00:00'),
        ]);

        $state = StateFactory::createOne(['name' => 'Bayern']);
        $dispatchArea = DispatchAreaFactory::createOne([
            'name' => 'München',
            'state' => $state,
        ]);
        $ownedHospital = HospitalFactory::createOne([
            'name' => 'Owned Klinik',
            'owner' => $profileUser,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
            'location' => HospitalLocation::URBAN,
            'tier' => HospitalTier::FULL,
            'size' => HospitalSize::LARGE,
            'beds' => 400,
        ]);
        HospitalAccessGrantFactory::createOne([
            'user' => $profileUser,
            'hospital' => HospitalFactory::createOne([
                'name' => 'Grant Klinik',
                'owner' => UserFactory::createOne(),
            ]),
        ]);

        ImportFactory::createOne([
            'createdBy' => $profileUser,
            'hospital' => $ownedHospital,
            'status' => ImportStatus::COMPLETED,
        ]);
        ImportFactory::createOne([
            'createdBy' => $profileUser,
            'hospital' => $ownedHospital,
            'status' => ImportStatus::PARTIAL,
        ]);
        ImportFactory::createOne([
            'createdBy' => $profileUser,
            'hospital' => $ownedHospital,
            'status' => ImportStatus::FAILED,
        ]);

        PostFactory::createOne([
            'createdBy' => $profileUser,
            'title' => 'Published Profile Post',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 day'),
        ]);
        PostFactory::createOne([
            'createdBy' => $profileUser,
            'title' => 'Draft Profile Post',
            'status' => PostStatus::DRAFT,
            'publishedAt' => null,
        ]);

        $this->backfillActivity();

        $crawler = $client->request(Request::METHOD_GET, '/explore/user/'.$profileUser->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#user-username', 'profile-user');
        self::assertSelectorExists('[data-testid="user-badge-participant"]');
        self::assertSelectorExists('[data-testid="user-badge-board-member"]');
        self::assertSelectorNotExists('[data-testid="user-badge-user"]');
        self::assertSelectorTextContains('[data-testid="user-member-since"]', 'March 2025');
        self::assertSelectorTextContains('[data-testid="user-profile-header"]', 'Owned Klinik');
        self::assertSelectorTextContains('[data-testid="user-profile-hospitals"]', 'Owned Klinik');
        self::assertSelectorTextContains('[data-testid="user-profile-hospitals"]', 'Grant Klinik');
        self::assertSelectorTextContains('[data-testid="user-profile-hospitals"]', 'München');
        self::assertSelectorTextContains('[data-testid="user-profile-hospitals"]', 'Bayern');
        self::assertSelectorTextContains('[data-testid="user-profile-hospitals"]', 'Urban');
        self::assertSelectorTextContains('[data-testid="user-profile-hospitals"]', 'Full');
        self::assertSelectorTextContains('[data-testid="user-profile-hospitals"]', 'Large');
        self::assertSelectorTextContains('[data-testid="user-profile-hospitals"]', '400');
        self::assertSelectorTextContains('[data-testid="user-import-count"]', '2');
        self::assertSelectorTextContains('[data-testid="user-post-count"]', '1');
        self::assertSelectorTextContains('[data-testid="user-profile-activity-feed"]', 'Published Profile Post');
        self::assertSelectorTextContains('[data-testid="user-profile-activity-feed"]', 'Joined the platform');
        self::assertSelectorTextNotContains('[data-testid="user-profile-activity-feed"]', 'Draft Profile Post');
        self::assertSelectorNotExists('.pagination');
        self::assertSelectorNotExists('[data-testid="profile-activity-next"]');
        self::assertStringNotContainsString('profile-secret@example.test', $client->getResponse()->getContent() ?: '');

        $activityTypes = $crawler->filter('[data-testid="profile-activity-item"]')->each(
            static fn ($node): string => (string) $node->attr('data-activity-type'),
        );
        self::assertContains('first_import', $activityTypes);
        self::assertSame('joined', $activityTypes[array_key_last($activityTypes)]);

        $client->request(Request::METHOD_GET, '/explore/user/'.$viewer->getPublicIdString());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="user-badge-self"]');
    }

    public function testAdminIsShownAsAdministratorNotOnlyParticipant(): void
    {
        $client = $this->createClientAsParticipant();

        $admin = UserFactory::createOne([
            'username' => 'site-admin',
            'roles' => [UserRole::USER, UserRole::ADMIN],
        ]);

        $client->request(Request::METHOD_GET, '/explore/user');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="user-directory-list"]', 'site-admin');

        $crawler = $client->request(Request::METHOD_GET, '/explore/user/'.$admin->getPublicIdString());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="user-badge-admin"]');
        self::assertSelectorNotExists('[data-testid="user-badge-participant"]');
        self::assertSelectorNotExists('[data-testid="user-badge-user"]');
        self::assertSelectorTextContains('[data-testid="user-profile-header"]', 'site-admin');
        unset($crawler);
    }

    public function testBoardMemberDoesNotGainAdminAccess(): void
    {
        $client = self::createClient();
        $boardMember = UserFactory::createOne([
            'username' => 'board-member',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::BOARD_MEMBER],
        ]);
        $client->loginUser($boardMember);

        $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    public function testListAvoidsObviousNPlusOne(): void
    {
        $users = UserFactory::createMany(5, [
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
        ]);
        foreach ($users as $user) {
            $hospital = HospitalFactory::createOne(['owner' => $user]);
            ImportFactory::createOne([
                'createdBy' => $user,
                'hospital' => $hospital,
                'status' => ImportStatus::COMPLETED,
            ]);
        }

        self::ensureKernelShutdown();

        $client = $this->createClientAsParticipant();
        $client->enableProfiler();
        $client->request(Request::METHOD_GET, '/explore/user');
        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertNotNull($profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        // List + count + hospital batch + filter choices + shared layout lookups.
        self::assertLessThan(
            40,
            $collector->getQueryCount(),
            sprintf('Expected a bounded query count on /explore/user, got %d.', $collector->getQueryCount()),
        );
    }

    public function testActivityEndpointRequiresParticipantAndHidesDraftsInLaterBatches(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/explore/user/00000000-0000-0000-0000-000000000001/activity');
        self::assertResponseRedirects('/login');

        self::ensureKernelShutdown();
        $forbidden = $this->createClientAsRoleUser();
        $profileUser = UserFactory::createOne([
            'username' => 'feed-user',
            'email' => 'feed-secret@example.test',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
            'createdAt' => new \DateTimeImmutable('2020-01-01 00:00:00'),
        ]);
        $forbidden->request(
            Request::METHOD_GET,
            '/explore/user/'.$profileUser->getPublicIdString().'/activity',
        );
        self::assertResponseStatusCodeSame(403);

        self::ensureKernelShutdown();
        $client = $this->createClientAsParticipant();

        for ($i = 1; $i <= 25; ++$i) {
            PostFactory::createOne([
                'createdBy' => $profileUser,
                'title' => sprintf('Feed Post %02d', $i),
                'slug' => sprintf('feed-post-%02d', $i),
                'status' => PostStatus::PUBLISHED,
                'publishedAt' => new \DateTimeImmutable(sprintf('2026-03-%02d 12:00:00', $i)),
            ]);
        }
        PostFactory::createOne([
            'createdBy' => $profileUser,
            'title' => 'Hidden Draft Feed Post',
            'slug' => 'hidden-draft-feed-post',
            'status' => PostStatus::DRAFT,
            'publishedAt' => null,
        ]);
        PostCommentFactory::createOne([
            'author' => $profileUser,
            'createdBy' => $profileUser,
            'post' => PostFactory::createOne([
                'createdBy' => $profileUser,
                'title' => 'Draft Commented',
                'slug' => 'draft-commented',
                'status' => PostStatus::DRAFT,
                'publishedAt' => null,
            ]),
            'content' => 'Secret draft comment',
            'createdAt' => new \DateTimeImmutable('2026-04-01 12:00:00'),
        ]);

        $this->backfillActivity();

        $crawler = $client->request(Request::METHOD_GET, '/explore/user/'.$profileUser->getPublicIdString());
        self::assertResponseIsSuccessful();
        self::assertCount(20, $crawler->filter('[data-testid="profile-activity-item"]'));
        self::assertSelectorExists('[data-testid="profile-activity-next"]');
        self::assertSelectorNotExists('.pagination');
        self::assertStringNotContainsString('Hidden Draft Feed Post', $client->getResponse()->getContent() ?: '');
        self::assertStringNotContainsString('Secret draft comment', $client->getResponse()->getContent() ?: '');
        self::assertStringNotContainsString('feed-secret@example.test', $client->getResponse()->getContent() ?: '');

        $nextSrc = $crawler->filter('[data-testid="profile-activity-next"]')->attr('src');
        self::assertNotNull($nextSrc);
        $frameId = $crawler->filter('[data-testid="profile-activity-next"]')->attr('id');
        self::assertNotNull($frameId);

        $nextCrawler = $client->request(
            Request::METHOD_GET,
            $nextSrc,
            [],
            [],
            ['HTTP_TURBO_FRAME' => $frameId],
        );
        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(6, $nextCrawler->filter('[data-testid="profile-activity-item"]')->count());
        self::assertSelectorTextContains('[data-activity-type="joined"]', 'Joined the platform');
        self::assertSelectorNotExists('[data-testid="profile-activity-next"]');
        self::assertStringNotContainsString('Hidden Draft Feed Post', $client->getResponse()->getContent() ?: '');
        self::assertStringNotContainsString('Secret draft comment', $client->getResponse()->getContent() ?: '');
        self::assertStringNotContainsString('feed-secret@example.test', $client->getResponse()->getContent() ?: '');
    }

    public function testJoinOnlyProfileDoesNotRenderLazyActivityFrame(): void
    {
        $client = $this->createClientAsParticipant();
        $profileUser = UserFactory::createOne([
            'username' => 'newcomer',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
            'createdAt' => new \DateTimeImmutable('2026-08-01 00:00:00'),
        ]);

        $this->backfillActivity();

        $crawler = $client->request(Request::METHOD_GET, '/explore/user/'.$profileUser->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="profile-activity-item"]'));
        self::assertSelectorExists('[data-activity-type="joined"]');
        self::assertSelectorNotExists('[data-testid="profile-activity-next"]');
    }

    private function backfillActivity(): void
    {
        self::getContainer()->get(UserActivityBackfill::class)->run(true);
    }
}
