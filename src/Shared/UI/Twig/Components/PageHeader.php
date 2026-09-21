<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'PageHeader', template: '@Shared/components/PageHeader.html.twig')]
final class PageHeader
{
    public ?string $title = null;

    public ?string $pretitle = null;

    public ?string $titleTestId = null;

    public ?string $pretitleTestId = null;

    public ?string $class = null;

    public string $actionsClass = 'btn-list';

    public ?string $actionsTestId = null;

    public function getPageHeaderClass(): string
    {
        $classes = ['page-header', 'd-print-none'];

        if (null !== $this->class && '' !== $this->class) {
            $classes[] = $this->class;
        }

        return implode(' ', $classes);
    }
}
