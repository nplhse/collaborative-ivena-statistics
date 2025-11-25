<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Reusable Card für eine Top-Kategorie (Label, Count, %).
 */
#[AsTwigComponent(name: 'TopCategoryCard', template: '@Statistics/components/TopCategoryCard.html.twig')]
final class TopCategoryCard
{
    /** @var list<array{id:int|null,label:string,count:int}> */
    public array $items = [];

    public string $title = '';
    public int $total = 0;
    public ?string $footer = null;

    public function hasData(): bool
    {
        return !empty($this->items);
    }
}
