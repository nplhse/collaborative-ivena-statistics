<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

/**
 * Maps a sort direction from a request or DTO ('asc' / 'desc', any case) to the SortDirection
 * enum that Doctrine's QueryBuilder::orderBy() expects. Same rules as Doctrine's own string
 * handling: anything else is rejected.
 */
final class SortDirections
{
    public static function fromString(string $direction): \SortDirection
    {
        return match (strtolower($direction)) {
            'asc' => \SortDirection::Ascending,
            'desc' => \SortDirection::Descending,
            default => throw new \InvalidArgumentException(\sprintf('Invalid sort direction "%s".', $direction)),
        };
    }
}
