<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'Modal', template: '@Shared/components/Modal.html.twig')]
final class Modal
{
    private const array SIZES = ['sm', 'lg', 'xl'];

    /** @psalm-suppress PropertyNotSetInConstructor Hydrated by Twig Component attributes. */
    public string $id;

    /** @psalm-suppress PropertyNotSetInConstructor Hydrated by Twig Component attributes. */
    public string $title;

    public string $size = '';

    public bool $scrollable = true;

    public function getLabelId(): string
    {
        return $this->id.'-label';
    }

    public function getDialogClass(): string
    {
        $classes = ['modal-dialog'];

        if (\in_array($this->size, self::SIZES, true)) {
            $classes[] = 'modal-'.$this->size;
        }

        $classes[] = 'modal-dialog-centered';

        if ($this->scrollable) {
            $classes[] = 'modal-dialog-scrollable';
        }

        return implode(' ', $classes);
    }
}
