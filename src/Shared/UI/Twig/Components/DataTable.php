<?php

namespace App\Shared\UI\Twig\Components;

use App\Shared\Infrastructure\Pagination\Paginator;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'DataTable', template: '@Shared/components/DataTable.html.twig')]
final class DataTable
{
    public ?string $title = null;

    public ?Paginator $paginator = null;

    public ?string $paginationRoute = null;

    /**
     * @var array<int, string>|null
     */
    public ?array $tabs = null;
}
