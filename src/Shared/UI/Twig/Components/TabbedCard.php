<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'TabbedCard', template: '@Shared/components/TabbedCard.html.twig')]
final class TabbedCard
{
    public ?string $title = null;

    /**
     * URL-driven tabs rendered in the card header (Tabler card-header-tabs).
     *
     * @var list<array{key: string, label: string, url: string, active: bool, testId?: string}>|null
     */
    public ?array $headerTabs = null;

    public ?string $headerTabsTestId = null;

    public ?string $class = null;

    public ?string $testId = null;

    public function getCssClass(): string
    {
        $classes = ['card'];

        if (null !== $this->class) {
            $classes[] = $this->class;
        }

        return implode(' ', $classes);
    }
}
