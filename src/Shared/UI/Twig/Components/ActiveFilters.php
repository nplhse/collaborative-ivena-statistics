<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'ActiveFilters', template: '@Shared/components/ActiveFilters.html.twig')]
final class ActiveFilters
{
    /**
     * @var list<array{label: string, value: string}>
     */
    public array $badges = [];

    public function hasBadges(): bool
    {
        return [] !== $this->badges;
    }
}
