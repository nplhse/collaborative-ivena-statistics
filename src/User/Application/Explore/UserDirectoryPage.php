<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

use App\Shared\Infrastructure\Pagination\Paginator;

final readonly class UserDirectoryPage
{
    /**
     * @param list<UserListItem>                 $items
     * @param list<array{id: int, name: string}> $hospitalChoices
     */
    public function __construct(
        public Paginator $paginator,
        public array $items,
        public array $hospitalChoices,
    ) {
    }
}
