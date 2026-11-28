<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Explore;

use App\User\Application\Explore\UserQueryParameters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How the query string of `/explore/user` is normalised. What the list does with the values is in
 * `ListExploreUsersQueryTest`; that they reach it over HTTP in `ExploreUsersControllerTest`.
 */
#[CoversClass(UserQueryParameters::class)]
final class UserQueryParametersTest extends TestCase
{
    public function testDefaults(): void
    {
        $parameters = new UserQueryParameters();

        self::assertSame(
            [1, 25, 'asc', 'username', null, null, false, false],
            [
                $parameters->page,
                $parameters->limit,
                $parameters->orderBy,
                $parameters->sortBy,
                $parameters->search,
                $parameters->hospitalId,
                $parameters->wantsParticipant(),
                $parameters->wantsBoardMember(),
            ],
        );
    }

    /** @return iterable<string, array{int|string|null, ?int}> */
    public static function hospitalIds(): iterable
    {
        yield 'integer' => [7, 7];
        yield 'digits' => ['7', 7];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
        yield 'zero' => ['0', null];
        yield 'negative integer' => [-3, null];
        yield 'negative string' => ['-3', null];
        yield 'not a number' => ['abc', null];
        yield 'decimal' => ['1.5', null];
    }

    #[DataProvider('hospitalIds')]
    public function testHospitalIdIsAPositiveIntegerOrNull(int|string|null $hospitalId, ?int $expected): void
    {
        $parameters = new UserQueryParameters(hospitalId: $hospitalId);

        self::assertSame($expected, $parameters->hospitalId);
    }

    /** @return iterable<string, array{string, string}> */
    public static function orderBys(): iterable
    {
        yield 'asc' => ['asc', 'asc'];
        yield 'desc' => ['desc', 'desc'];
        yield 'upper case with spaces' => [' DESC ', 'desc'];
        yield 'unknown' => ['sideways', 'asc'];
    }

    #[DataProvider('orderBys')]
    public function testOrderByFallsBackToAscending(string $orderBy, string $expected): void
    {
        $parameters = new UserQueryParameters(orderBy: $orderBy);

        self::assertSame($expected, $parameters->orderBy);
    }

    /** @return iterable<string, array{string, string}> */
    public static function sortBys(): iterable
    {
        yield 'username' => ['username', 'username'];
        yield 'createdAt' => ['createdAt', 'createdAt'];
        yield 'snake case' => ['created_at', 'createdAt'];
        yield 'upper case with spaces' => [' CREATEDAT ', 'createdAt'];
        yield 'unknown' => ['email', 'username'];
    }

    #[DataProvider('sortBys')]
    public function testSortByFallsBackToUsername(string $sortBy, string $expected): void
    {
        $parameters = new UserQueryParameters(sortBy: $sortBy);

        self::assertSame($expected, $parameters->sortBy);
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function filterValues(): iterable
    {
        yield '1' => ['1', true];
        yield 'true' => ['true', true];
        yield 'yes' => ['yes', true];
        yield 'on' => ['on', true];
        yield 'upper case' => ['YES', true];
        yield '0' => ['0', false];
        yield 'no' => ['no', false];
        yield 'empty' => ['', false];
        yield 'missing' => [null, false];
    }

    #[DataProvider('filterValues')]
    public function testParticipantAndBoardMemberFiltersAcceptTruthyValues(?string $value, bool $expected): void
    {
        $parameters = new UserQueryParameters(participant: $value, boardMember: $value);

        self::assertSame([$expected, $expected], [$parameters->wantsParticipant(), $parameters->wantsBoardMember()]);
    }
}
