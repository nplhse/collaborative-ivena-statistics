<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\SortDirections;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SortDirectionsTest extends TestCase
{
    #[DataProvider('directions')]
    public function testMapsDirectionsCaseInsensitively(string $direction, \SortDirection $expected): void
    {
        self::assertSame($expected, SortDirections::fromString($direction));
    }

    /**
     * @return iterable<string, array{string, \SortDirection}>
     */
    public static function directions(): iterable
    {
        yield 'asc' => ['asc', \SortDirection::Ascending];
        yield 'ASC' => ['ASC', \SortDirection::Ascending];
        yield 'desc' => ['desc', \SortDirection::Descending];
        yield 'DESC' => ['DESC', \SortDirection::Descending];
        yield 'Desc' => ['Desc', \SortDirection::Descending];
    }

    #[DataProvider('invalidDirections')]
    public function testRejectsAnythingElse(string $direction): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SortDirections::fromString($direction);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDirections(): iterable
    {
        yield 'empty' => [''];
        yield 'word' => ['ascending'];
        yield 'injection' => ['ASC, (SELECT 1)'];
    }
}
