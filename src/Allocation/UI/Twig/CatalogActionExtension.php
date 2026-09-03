<?php

declare(strict_types=1);

namespace App\Allocation\UI\Twig;

use App\Allocation\Application\DTO\CatalogAction;
use App\Allocation\Application\Explore\Catalog\CatalogActionFactory;
use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;

final readonly class CatalogActionExtension
{
    public function __construct(
        private CatalogActionFactory $actionFactory,
    ) {
    }

    #[\Twig\Attribute\AsTwigFunction(name: 'catalog_top_list_action')]
    public function catalogTopListAction(string $dimension): ?CatalogAction
    {
        return $this->actionFactory->forCatalogList(CatalogDimensionKey::from($dimension));
    }
}
