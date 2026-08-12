<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller\Explore;

use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
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

        $ownedHospital = HospitalFactory::createOne([
            'name' => 'Owned Klinik',
            'owner' => $profileUser,
        ]);
        HospitalAccessGrantFactory::createOne([
            'user' => $profileUser,
            'hospital' => HospitalFactory::createOne(['name' => 'Grant Klinik']),
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

        $client->request(Request::METHOD_GET, '/explore/user/'.$profileUser->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#user-username', 'profile-user');
        self::assertSelectorExists('[data-testid="user-badge-participant"]');
        self::assertSelectorExists('[data-testid="user-badge-board-member"]');
        self::assertSelectorTextContains('[data-testid="user-member-since"]', '15.03.2025');
        self::assertSelectorTextContains('[data-testid="user-created-at"]', '15.03.2025 10:30');
        self::assertSelectorTextContains('[data-testid="user-updated-at"]', '01.08.2026 14:00');
        self::assertSelectorTextContains('[data-testid="user-profile-hospitals"]', 'Owned Klinik');
        self::assertSelectorTextContains('[data-testid="user-profile-hospitals"]', 'Grant Klinik');
        self::assertSelectorTextContains('[data-testid="user-import-count"]', '2');
        self::assertSelectorTextContains('[data-testid="user-profile-posts"]', 'Published Profile Post');
        self::assertSelectorTextNotContains('[data-testid="user-profile-posts"]', 'Draft Profile Post');
        self::assertStringNotContainsString('profile-secret@example.test', $client->getResponse()->getContent() ?: '');

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
}
