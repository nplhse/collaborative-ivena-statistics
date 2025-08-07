<?php

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Card
{
    public ?string $class = null;

    public ?string $size = null;

    public ?string $title = null;

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
