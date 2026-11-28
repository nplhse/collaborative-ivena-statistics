<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Query;

use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Explore\UserQueryParameters;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Query\ListExploreUsersQuery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Search, filters and sort order of the user directory (`/explore/user`). How the query string becomes
 * `UserQueryParameters` is in `UserQueryParametersTest`; that the page passes it on in
 * `ExploreUsersControllerTest::testQueryParametersReachTheList`.
 */
final class ListExploreUsersQueryTest extends DatabaseKernelTestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function usernameOrders(): iterable
    {
        yield 'ascending by default' => ['asc', ['Alpha-User', 'middle-user', 'zeta-user']];
        yield 'descending' => ['desc', ['zeta-user', 'middle-user', 'Alpha-User']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('usernameOrders')]
    public function testSortsByUsernameIgnoringTheCase(string $orderBy, array $expected): void
    {
        UserFactory::createOne(['username' => 'zeta-user']);
        UserFactory::createOne(['username' => 'Alpha-User']);
        UserFactory::createOne(['username' => 'middle-user']);

        $usernames = $this->list(new UserQueryParameters(orderBy: $orderBy));

        self::assertSame($expected, $usernames);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function createdAtOrders(): iterable
    {
        yield 'ascending' => ['asc', ['oldest-user', 'middle-created-user', 'newest-user']];
        yield 'descending' => ['desc', ['newest-user', 'middle-created-user', 'oldest-user']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('createdAtOrders')]
    public function testSortsByCreatedAt(string $orderBy, array $expected): void
    {
        UserFactory::createOne(['username' => 'newest-user', 'createdAt' => new \DateTimeImmutable('2026-08-10 12:00:00')]);
        UserFactory::createOne(['username' => 'oldest-user', 'createdAt' => new \DateTimeImmutable('2024-01-05 08:00:00')]);
        UserFactory::createOne(['username' => 'middle-created-user', 'createdAt' => new \DateTimeImmutable('2025-06-01 09:00:00')]);

        $usernames = $this->list(new UserQueryParameters(orderBy: $orderBy, sortBy: 'createdAt'));

        self::assertSame($expected, $usernames);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function searches(): iterable
    {
        yield 'part of the username' => ['searchable', ['searchable-user']];
        yield 'display name, other case' => ['MÜLLER', ['mmueller']];
        yield 'name of an owned hospital' => ['owned klinik', ['alice']];
        // bob owns the hospital alice has access to
        yield 'name of a hospital with access' => ['grant klinik', ['alice', 'bob']];
        yield 'blank' => ['  ', ['alice', 'bob', 'mmueller', 'searchable-user']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('searches')]
    public function testSearchesUsernameDisplayNameAndHospitalNames(string $search, array $expected): void
    {
        $alice = UserFactory::createOne(['username' => 'alice']);
        $bob = UserFactory::createOne(['username' => 'bob']);
        UserFactory::createOne(['username' => 'searchable-user']);
        UserFactory::createOne(['username' => 'mmueller', 'displayName' => 'Michael Müller']);
        HospitalFactory::createOne(['name' => 'Owned Klinik', 'owner' => $alice, 'createdBy' => $alice]);
        HospitalAccessGrantFactory::createOne([
            'user' => $alice,
            'hospital' => HospitalFactory::createOne(['name' => 'Grant Klinik', 'owner' => $bob, 'createdBy' => $bob]),
            'createdBy' => $bob,
        ]);

        $usernames = $this->list(new UserQueryParameters(search: $search));

        self::assertSame($expected, $usernames);
    }

    /** @return iterable<string, array{UserQueryParameters, list<string>}> */
    public static function roleFilters(): iterable
    {
        // administrators count as participants
        yield 'participant' => [new UserQueryParameters(participant: '1'), ['admin', 'board-member', 'participant']];
        yield 'board member' => [new UserQueryParameters(boardMember: '1'), ['board-member']];
        yield 'both' => [new UserQueryParameters(participant: '1', boardMember: '1'), ['board-member']];
        yield 'none' => [new UserQueryParameters(), ['admin', 'board-member', 'participant', 'plain-user']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('roleFilters')]
    public function testFiltersByParticipantAndBoardMember(UserQueryParameters $parameters, array $expected): void
    {
        UserFactory::createOne(['username' => 'plain-user', 'roles' => [UserRole::USER]]);
        UserFactory::createOne(['username' => 'participant', 'roles' => [UserRole::USER, UserRole::PARTICIPANT]]);
        UserFactory::createOne(['username' => 'admin', 'roles' => [UserRole::USER, UserRole::ADMIN]]);
        UserFactory::createOne(['username' => 'board-member', 'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::BOARD_MEMBER]]);

        $usernames = $this->list($parameters);

        self::assertSame($expected, $usernames);
    }

    public function testFiltersByOwnedOrAccessibleHospital(): void
    {
        $alice = UserFactory::createOne(['username' => 'alice']);
        $bob = UserFactory::createOne(['username' => 'bob']);
        $carol = UserFactory::createOne(['username' => 'carol']);
        $hospital = HospitalFactory::createOne(['name' => 'Filter Klinik', 'owner' => $alice, 'createdBy' => $alice]);
        HospitalAccessGrantFactory::createOne(['user' => $bob, 'hospital' => $hospital, 'createdBy' => $alice]);
        HospitalFactory::createOne(['name' => 'Other Klinik', 'owner' => $carol, 'createdBy' => $carol]);

        $usernames = $this->list(new UserQueryParameters(hospitalId: (string) $hospital->getId()));

        self::assertSame(['alice', 'bob'], $usernames);
    }

    public function testLeavesOutDisabledUsers(): void
    {
        UserFactory::createOne(['username' => 'alice']);
        UserFactory::createOne(['username' => 'bob', 'isEnabled' => false]);

        $usernames = $this->list(new UserQueryParameters());

        self::assertSame(['alice'], $usernames);
    }

    /**
     * @return list<string|null>
     */
    private function list(UserQueryParameters $parameters): array
    {
        /** @var list<User> $users */
        $users = (self::getContainer()->get(ListExploreUsersQuery::class))($parameters)->getQuery()->getResult();

        return array_map(static fn (User $user): ?string => $user->getUsername(), $users);
    }
}
