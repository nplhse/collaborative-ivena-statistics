<?php

namespace App\Twig\Components;

use App\Pagination\Paginator;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class DataTable
{
    public ?string $title = null;

    public ?Paginator $paginator = null;

    public ?string $paginationRoute = null;
}
