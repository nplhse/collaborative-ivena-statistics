<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Ordered list of TimelineItem components along a vertical line. Presentation only; the caller decides
 * order and grouping.
 */
#[AsTwigComponent(name: 'Timeline', template: '@Shared/components/Timeline.html.twig')]
final class Timeline
{
    /** @psalm-suppress PossiblyUnusedProperty Consumed by Timeline.html.twig. */
    public ?string $label = null;

    /**
     * Nested timelines (inside an expanded item) are drawn without the outer line.
     *
     * @psalm-suppress PossiblyUnusedProperty Consumed by Timeline.html.twig.
     */
    public bool $nested = false;
}
