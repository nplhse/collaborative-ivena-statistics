<?php

namespace App\Twig\Components;

use App\Pagination\Paginator;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Card
{
    public ?string $class = null;

    public ?string $size = null;

    public ?string $title = null;

    public ?Paginator $paginator = null;

    public ?string $paginationRoute = null;

    public bool $noPadding = false;

    public function getCssClass(): string
    {
        $classes = ['card'];

        if ('md' === $this->size) {
            $classes[] = 'card-md';
        }

        if (null !== $this->class) {
            $classes[] = $this->class;
        }

        return implode(' ', $classes);
    }
}
