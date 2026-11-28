<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller\Explore;

use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\flush_after;

/**
 * Access, routing and page wiring of the user directory and profiles under `/explore/user`. Search, filters and sort
 * order are in `ListExploreUsersQueryTest`, the normalised query string in `UserQueryParametersTest`; which role gets
 * which badge in `UserPublicRoleResolverTest`; the hospital fields (dispatch area, state, location, tier, size, beds)
 * in `UserHospitalRelationsQueryTest`; order and paging of the activity feed in `UserProfileActivityQueryTest`, that
 * drafts are not backfilled in `UserActivityBackfillTest`; the avatar in `UserAvatarComponentTest`; the cards layout
 * in `DataTableComponentTest` and the filter drawer in `FilterDrawerComponentTest` / `FilterDrawerTriggerComponentTest`.
 * That a board member gets no administration access is in `AdminAccessTest`.
 */
#[ResetDatabase]
final class ExploreUsersControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use InteractsWithAuthenticatedUser;

    /** Measured 18 queries for /explore/user (#1348), the same with 6 or 16 listed users; 4 to spare. */
    private const int QUERY_LIMIT = 22;

    /** @return iterable<string, array{string}> */
    public static function guestUrls(): iterable
    {
        yield 'list' => ['/explore/user'];
        yield 'activity' => ['/explore/user/00000000-0000-0000-0000-000000000001/activity'];
    }

    #[DataProvider('guestUrls')]
    public function testGuestsAreRedirectedToLogin(string $url): void
    {
        $client = self::createClient();

        $client->request(Request::METHOD_GET, $url);

        self::assertResponseRedirects('/login');
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenForNonParticipants(): iterable
    {
        yield 'list' => ['/explore/user'];
        yield 'other profile' => ['/explore/user/Hedy'];
        yield 'other profile, other case' => ['/explore/user/hedy'];
        yield 'other activity' => ['/explore/user/Hedy/activity'];
        yield 'rest of Explore' => ['/explore'];
        yield 'unknown page below a profile' => ['/explore/user/00000000-0000-0000-0000-000000000001/something'];
    }

    #[DataProvider('forbiddenForNonParticipants')]
    public function testNonParticipantIsForbidden(string $url): void
    {
        $client = $this->createClientAsRoleUser();
        UserFactory::createOne(['username' => 'Hedy', 'roles' => [UserRole::USER, UserRole::PARTICIPANT]]);

        $client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(403);
    }

    public function testNonParticipantMayOpenOwnProfileWithoutExploreLinks(): void
    {
        $client = self::createClient();
        $viewer = $this->loginAsRoleUser($client);
        HospitalAccessGrantFactory::createOne([
            'user' => $viewer,
            'hospital' => HospitalFactory::createOne([
                'name' => 'Grant Klinik',
                'owner' => UserFactory::createOne(),
            ]),
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/user/'.$viewer->getUsername());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="user-badge-self"]');
        self::assertSelectorTextContains('[data-testid="user-profile-hospitals"]', 'Grant Klinik');
        self::assertSelectorNotExists('[data-testid="user-profile-back-to-list"]');
        self::assertCount(0, $crawler->filter('a[href^="/explore/hospital"]'), 'hospital pages are Explore, which needs ROLE_PARTICIPANT');
        self::assertCount(0, $crawler->filter('.breadcrumb a[href="/explore"], .breadcrumb a[href="/explore/user"]'));
    }

    public function testNonParticipantMayLoadOwnActivity(): void
    {
        $client = self::createClient();
        $viewer = $this->loginAsRoleUser($client);

        $client->request(Request::METHOD_GET, '/explore/user/'.$viewer->getUsername().'/activity');

        self::assertResponseIsSuccessful();
    }

    public function testParticipantProfileLinksIntoExplore(): void
    {
        $client = self::createClient();
        $viewer = $this->loginAsParticipant($client);
        HospitalFactory::createOne(['name' => 'Own Klinik', 'owner' => $viewer]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/user/'.$viewer->getUsername());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="user-profile-back-to-list"]');
        self::assertGreaterThan(0, $crawler->filter('a[href^="/explore/hospital"]')->count());
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function profileUrls(): iterable
    {
        // requested url, redirect target (null: the profile itself answers)
        yield 'exact username' => ['/explore/user/Ada.Lovelace', null];
        yield 'other case' => ['/explore/user/ada.lovelace', '/explore/user/Ada.Lovelace'];
        yield 'activity in other case keeps the query' => ['/explore/user/ADA.LOVELACE/activity?cursor=abc', '/explore/user/Ada.Lovelace/activity?cursor=abc'];
        yield 'unicode username' => ['/explore/user/j%C3%BCrgen', null];
        yield 'unicode username, other case' => ['/explore/user/J%C3%9CRGEN', '/explore/user/j%C3%BCrgen'];
        // usernames from before the username rule keep a profile (NormalizeUsernamesCommand renames them)
        yield 'legacy username with a space' => ['/explore/user/John%20Doe', null];
    }

    #[DataProvider('profileUrls')]
    public function testProfileIsReachedByUsernameIgnoringTheCase(string $url, ?string $redirect): void
    {
        $client = $this->createClientAsParticipant();
        UserFactory::createSequence([
            ['username' => 'Ada.Lovelace', 'roles' => [UserRole::USER, UserRole::PARTICIPANT]],
            ['username' => 'jürgen', 'roles' => [UserRole::USER, UserRole::PARTICIPANT]],
            ['username' => 'John Doe', 'roles' => [UserRole::USER, UserRole::PARTICIPANT]],
        ]);

        $client->request(Request::METHOD_GET, $url);

        null === $redirect ? self::assertResponseIsSuccessful() : self::assertResponseRedirects($redirect, 301);
    }

    /** @return iterable<string, array{string}> */
    public static function missingProfiles(): iterable
    {
        yield 'unknown username' => ['/explore/user/nobody'];
        yield 'activity of an unknown username' => ['/explore/user/nobody/activity'];
        yield 'uuid' => ['/explore/user/00000000-0000-4000-8000-000000000001'];
        yield 'disabled user' => ['/explore/user/gone'];
        yield 'disabled user, other case' => ['/explore/user/GONE'];
        yield 'activity of a disabled user' => ['/explore/user/gone/activity'];
    }

    #[DataProvider('missingProfiles')]
    public function testUnknownOrDisabledProfileIsNotFound(string $url): void
    {
        $client = $this->createClientAsParticipant();
        UserFactory::createOne(['username' => 'gone', 'isEnabled' => false, 'roles' => [UserRole::USER, UserRole::PARTICIPANT]]);

        $client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(404);
    }

    public function testListShowsUsersForParticipantWithoutExposingEmail(): void
    {
        $client = $this->createClientAsParticipant();
        UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice-secret@example.test',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::BOARD_MEMBER],
        ]);

        $client->request(Request::METHOD_GET, '/explore/user');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="user-directory-list"]', 'alice');
        self::assertSelectorExists('[data-testid="user-directory-row"] [data-testid="user-badge-participant"]');
        self::assertSelectorExists('[data-testid="user-directory-row"] [data-testid="user-badge-board-member"]');
        self::assertStringNotContainsString('alice-secret@example.test', (string) $client->getResponse()->getContent());
    }

    public function testListUsesCardsWithTheUsernameAsTitle(): void
    {
        $client = $this->createClientAsParticipant();

        $client->request(Request::METHOD_GET, '/explore/user');

        self::assertSelectorExists('[data-testid="data-table-cards"].data-table-cards--md [data-testid="user-directory-row"] td.data-table-cell--primary a');
    }

    public function testDirectoryLinksProfilesByUsername(): void
    {
        $client = $this->createClientAsParticipant();
        UserFactory::createOne([
            'username' => 'mmueller',
            'displayName' => 'Michael Müller',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/user', ['search' => 'mmueller']);

        $link = $crawler->filter('[data-testid="user-directory-row"] a[href^="/explore/user/"]')->last();
        self::assertSame(
            ['/explore/user/mmueller', '_top', 'Michael Müller'],
            [$link->attr('href'), $link->attr('target'), trim($link->text())],
            'profiles are linked by username (#1353) and leave the user-list turbo frame',
        );
    }

    public function testQueryParametersReachTheList(): void
    {
        $client = $this->createClientAsParticipant();
        $alice = UserFactory::createOne([
            'username' => 'searchable-alice',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::BOARD_MEMBER],
        ]);
        UserFactory::createOne(['username' => 'searchable-bob', 'roles' => [UserRole::USER, UserRole::PARTICIPANT]]);
        UserFactory::createOne(['username' => 'carol', 'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::BOARD_MEMBER]]);
        $hospital = HospitalFactory::createOne(['name' => 'Filter Klinik', 'owner' => $alice]);

        $client->request(Request::METHOD_GET, '/explore/user', [
            'search' => 'searchable',
            'participant' => 'yes',
            'boardMember' => 'on',
            'hospitalId' => (string) $hospital->getId(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="user-directory-list"]', 'searchable-alice');
        self::assertSelectorTextNotContains('[data-testid="user-directory-list"]', 'searchable-bob');
        self::assertSelectorTextNotContains('[data-testid="user-directory-list"]', 'carol');
        self::assertSelectorTextSame('[data-testid="user-directory-active-filter-count"]', '3');
        self::assertSelectorExists('[data-testid="user-directory-active-filters"]');
    }

    public function testFilterDrawerKeepsTheSearch(): void
    {
        $client = $this->createClientAsParticipant();

        $client->request(Request::METHOD_GET, '/explore/user', ['search' => 'alpha', 'sortBy' => 'username', 'orderBy' => 'desc']);

        self::assertSelectorExists('[data-testid="user-directory-filter-form"] input[type="hidden"][name="search"][value="alpha"]');
    }

    public function testListAvoidsObviousNPlusOne(): void
    {
        flush_after(static function (): void {
            foreach (UserFactory::createMany(5, ['roles' => [UserRole::USER, UserRole::PARTICIPANT]]) as $user) {
                ImportFactory::createOne([
                    'createdBy' => $user,
                    'hospital' => HospitalFactory::createOne(['owner' => $user]),
                    'status' => ImportStatus::COMPLETED,
                ]);
            }
        });
        self::ensureKernelShutdown();
        $client = $this->createClientAsParticipant();
        $client->enableProfiler();

        $client->request(Request::METHOD_GET, '/explore/user');

        self::assertResponseIsSuccessful();
        $collector = $client->getProfile() ? $client->getProfile()->getCollector('db') : null;
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        // List, count, one hospital batch, filter choices and the shared layout lookups do not grow with the listed
        // users; one query per listed user would add at least five and exceed the limit.
        self::assertLessThanOrEqual(
            self::QUERY_LIMIT,
            $collector->getQueryCount(),
            sprintf('Expected a bounded query count on /explore/user, got %d.', $collector->getQueryCount()),
        );
    }

    /** @return iterable<string, array{list<string>, list<string>}> */
    public static function roleBadges(): iterable
    {
        yield 'user only' => [[UserRole::USER], ['user-badge-user']];
        yield 'participant and board member' => [[UserRole::USER, UserRole::PARTICIPANT, UserRole::BOARD_MEMBER], ['user-badge-participant', 'user-badge-board-member']];
        // an administrator is shown as such, not only as participant
        yield 'administrator' => [[UserRole::USER, UserRole::ADMIN], ['user-badge-admin']];
    }

    /**
     * @param list<string> $roles
     * @param list<string> $badges
     */
    #[DataProvider('roleBadges')]
    public function testProfileShowsTheRoleBadges(array $roles, array $badges): void
    {
        $client = $this->createClientAsParticipant();
        UserFactory::createOne(['username' => 'alice', 'roles' => $roles]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/user/alice');

        self::assertSame(
            $badges,
            $crawler->filter('[data-testid="user-profile-header"] [data-testid^="user-badge-"]')->each(
                static fn ($badge): string => (string) $badge->attr('data-testid'),
            ),
        );
    }

    public function testProfileUsesTheDisplayName(): void
    {
        $client = $this->createClientAsParticipant();
        UserFactory::createOne(['username' => 'titled-user', 'displayName' => 'Tina Titel']);

        $client->request(Request::METHOD_GET, '/explore/user/titled-user');

        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Tina Titel');
        self::assertSelectorTextContains('.breadcrumb', 'Tina Titel');
        self::assertSelectorTextSame('#user-username', 'Tina Titel');
        self::assertSelectorTextSame('[data-testid="user-profile-username"]', '@titled-user');
    }

    public function testProfileHeaderShowsMemberSinceAndOwnedHospitalWithoutEmail(): void
    {
        $client = $this->createClientAsParticipant();
        $alice = UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice-secret@example.test',
            'createdAt' => new \DateTimeImmutable('2025-03-15 10:30:00'),
        ]);
        HospitalFactory::createOne(['name' => 'Owned Klinik', 'owner' => $alice]);

        $client->request(Request::METHOD_GET, '/explore/user/alice');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#user-username', 'alice');
        self::assertSelectorTextContains('[data-testid="user-member-since"]', 'March 2025');
        self::assertSelectorTextContains('[data-testid="user-profile-header"]', 'Owned Klinik');
        self::assertStringNotContainsString('alice-secret@example.test', (string) $client->getResponse()->getContent());
    }

    public function testProfileListsOwnedAndAccessibleHospitals(): void
    {
        $client = $this->createClientAsParticipant();
        $alice = UserFactory::createOne(['username' => 'alice']);
        HospitalFactory::createOne(['name' => 'Owned Klinik', 'owner' => $alice]);
        HospitalAccessGrantFactory::createOne([
            'user' => $alice,
            'hospital' => HospitalFactory::createOne(['name' => 'Grant Klinik', 'owner' => UserFactory::createOne()]),
        ]);

        $client->request(Request::METHOD_GET, '/explore/user/alice');

        self::assertSelectorTextContains('[data-testid="user-profile-hospitals"]', 'Owned Klinik');
        self::assertSelectorTextContains('[data-testid="user-profile-hospitals"]', 'Grant Klinik');
    }

    public function testProfileCountsSuccessfulImportsAndPublishedPosts(): void
    {
        $client = $this->createClientAsParticipant();
        $alice = UserFactory::createOne(['username' => 'alice']);
        $hospital = HospitalFactory::createOne(['owner' => $alice]);
        ImportFactory::createSequence([
            ['createdBy' => $alice, 'hospital' => $hospital, 'status' => ImportStatus::COMPLETED],
            ['createdBy' => $alice, 'hospital' => $hospital, 'status' => ImportStatus::PARTIAL],
            ['createdBy' => $alice, 'hospital' => $hospital, 'status' => ImportStatus::FAILED],
        ]);
        PostFactory::createSequence([
            ['createdBy' => $alice, 'status' => PostStatus::PUBLISHED, 'publishedAt' => new \DateTimeImmutable('2026-08-31 12:00:00')],
            ['createdBy' => $alice, 'status' => PostStatus::DRAFT, 'publishedAt' => null],
        ]);

        $client->request(Request::METHOD_GET, '/explore/user/alice');

        self::assertSelectorTextSame('[data-testid="user-import-count"]', '2');
        self::assertSelectorTextSame('[data-testid="user-post-count"]', '1');
    }

    public function testProfileActivityFeedShowsPublishedPostsAndTheJoin(): void
    {
        self::mockTime('2026-09-01 12:00:00');
        $client = $this->createClientAsParticipant();
        $alice = UserFactory::createOne(['username' => 'alice', 'createdAt' => new \DateTimeImmutable('2025-03-15 10:30:00')]);
        PostFactory::createSequence([
            [
                'createdBy' => $alice,
                'title' => 'Published Profile Post',
                'slug' => 'published-profile-post',
                'content' => '<p>Profile preview paragraph</p><p>More</p>',
                'status' => PostStatus::PUBLISHED,
                'publishedAt' => new \DateTimeImmutable('2026-08-31 12:00:00'),
            ],
            ['createdBy' => $alice, 'title' => 'Draft Profile Post', 'status' => PostStatus::DRAFT, 'publishedAt' => null],
        ]);
        $this->backfillActivity();

        $crawler = $client->request(Request::METHOD_GET, '/explore/user/alice');

        self::assertSelectorTextContains('[data-testid="user-profile-activity-feed"]', 'Published Profile Post');
        // the preview is the first paragraph only
        self::assertSelectorTextContains('[data-testid="activity-post-preview"]', 'Profile preview paragraph');
        self::assertSelectorTextNotContains('[data-testid="user-profile-activity-feed"]', 'More');
        self::assertSelectorTextNotContains('[data-testid="user-profile-activity-feed"]', 'Draft Profile Post');
        self::assertSelectorTextContains('[data-activity-type="joined"]', 'joined the platform');
        self::assertSelectorNotExists('.pagination');
        self::assertSelectorNotExists('[data-testid="profile-activity-next"]');
        $time = $crawler->filter('[data-testid="profile-activity-item"] time')->first();
        self::assertNotEmpty($time->attr('datetime'));
        self::assertNotEmpty($time->attr('title'));
        self::assertDoesNotMatchRegularExpression('/^\d{2}\.\d{2}\.\d{4}$/', trim($time->text()), 'relative time, the date is in the title');
    }

    public function testJoinOnlyProfileDoesNotRenderLazyActivityFrame(): void
    {
        $client = $this->createClientAsParticipant();
        UserFactory::createOne([
            'username' => 'newcomer',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
            'createdAt' => new \DateTimeImmutable('2026-08-01 00:00:00'),
        ]);
        $this->backfillActivity();

        $crawler = $client->request(Request::METHOD_GET, '/explore/user/newcomer');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="profile-activity-item"]'));
        self::assertSelectorExists('[data-activity-type="joined"]');
        self::assertSelectorNotExists('[data-testid="profile-activity-next"]');
    }

    public function testNextActivityFrameLoadsTheRestWithoutDrafts(): void
    {
        $client = $this->createClientAsParticipant();
        $alice = UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice-secret@example.test',
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
            'createdAt' => new \DateTimeImmutable('2020-01-01 00:00:00'),
        ]);
        // 25 posts: the first page shows 20 activities (ProfileActivityPage::PAGE_SIZE), the frame the rest and the join
        flush_after(static function () use ($alice): void {
            PostFactory::createSequence(static function () use ($alice): iterable {
                for ($i = 1; $i <= 25; ++$i) {
                    yield [
                        'createdBy' => $alice,
                        'title' => sprintf('Feed Post %02d', $i),
                        'slug' => sprintf('feed-post-%02d', $i),
                        'status' => PostStatus::PUBLISHED,
                        'publishedAt' => new \DateTimeImmutable(sprintf('2026-03-%02d 12:00:00', $i)),
                    ];
                }
            });
        });
        PostFactory::createOne(['createdBy' => $alice, 'title' => 'Hidden Draft Feed Post', 'status' => PostStatus::DRAFT, 'publishedAt' => null]);
        PostCommentFactory::createOne([
            'author' => $alice,
            'createdBy' => $alice,
            'post' => PostFactory::createOne(['createdBy' => $alice, 'title' => 'Draft Commented', 'status' => PostStatus::DRAFT, 'publishedAt' => null]),
            'content' => 'Secret draft comment',
            'createdAt' => new \DateTimeImmutable('2026-04-01 12:00:00'),
        ]);
        $this->backfillActivity();
        $next = $client->request(Request::METHOD_GET, '/explore/user/alice')->filter('[data-testid="profile-activity-next"]');

        $crawler = $client->request(Request::METHOD_GET, (string) $next->attr('src'), [], [], ['HTTP_TURBO_FRAME' => (string) $next->attr('id')]);

        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(6, $crawler->filter('[data-testid="profile-activity-item"]')->count());
        self::assertSelectorTextContains('[data-activity-type="joined"]', 'joined the platform');
        self::assertSelectorNotExists('[data-testid="profile-activity-next"]');
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Hidden Draft Feed Post', $content);
        self::assertStringNotContainsString('Secret draft comment', $content);
        self::assertStringNotContainsString('alice-secret@example.test', $content);
    }

    /**
     * The profile feed reads the user_activity projection; the tests fill it the way the backfill command does.
     */
    private function backfillActivity(): void
    {
        self::getContainer()->get(UserActivityBackfill::class)->run(true);
    }
}
