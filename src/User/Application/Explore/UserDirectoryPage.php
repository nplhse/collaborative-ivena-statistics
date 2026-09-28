<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

use Symfony\UX\Pagination\PaginationInterface;

final readonly class UserDirectoryPage
{
    /**
     * @param PaginationInterface<mixed>         $paginator
     * @param list<UserListItem>                 $items
     * @param list<array{id: int, name: string}> $hospitalChoices
     */
    public function __construct(
        public PaginationInterface $paginator,
        public array $items,
        public array $hospitalChoices,
    ) {
    }
}
