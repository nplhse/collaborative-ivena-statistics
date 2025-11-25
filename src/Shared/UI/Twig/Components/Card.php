<?php

namespace App\Shared\UI\Twig\Components;

use App\Shared\Infrastructure\Pagination\Paginator;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'Card', template: '@Shared/components/Card.html.twig')]
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
