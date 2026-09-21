<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'EmptyState', template: '@Shared/components/EmptyState.html.twig')]
final class EmptyState
{
    /** @psalm-suppress PossiblyUnusedProperty Consumed by EmptyState.html.twig. */
    public ?string $title = null;

    /** @psalm-suppress PossiblyUnusedProperty Consumed by EmptyState.html.twig. */
    public ?string $description = null;

    /** @psalm-suppress PossiblyUnusedProperty Consumed by EmptyState.html.twig. */
    public string $icon = 'tabler:mood-sad';
}
