<?php

declare(strict_types=1);

namespace App\Allocation\UI\Twig;

final readonly class CatalogListColumnsExtension
{
    /**
     * @param list<array<string, mixed>> $extra
     *
     * @return list<array<string, mixed>>
     */
    #[\Twig\Attribute\AsTwigFunction(name: 'catalog_list_columns')]
    public function columns(string $showRoute, array $extra = []): array
    {
        return CatalogListColumns::standard($showRoute, $extra);
    }
}
