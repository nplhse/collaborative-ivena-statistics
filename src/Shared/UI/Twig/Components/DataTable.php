<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use App\Shared\Infrastructure\Pagination\CursorPaginator;
use App\Shared\Infrastructure\Pagination\Paginator;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'DataTable', template: '@Shared/components/DataTable.html.twig')]
final class DataTable
{
    public ?string $title = null;

    public Paginator|CursorPaginator|null $paginator = null;

    public ?string $paginationRoute = null;

    public bool $showPaginationFooter = true;

    public ?string $paginationTemplate = null;

    /**
     * @var array<int, string>|null
     */
    public ?array $tabs = null;
}
