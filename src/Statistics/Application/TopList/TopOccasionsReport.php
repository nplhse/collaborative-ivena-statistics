<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Allocation\Domain\Entity\Occasion;

final readonly class TopOccasionsReport extends AbstractTopNTableReport
{
    #[\Override]
    public function key(): string
    {
        return 'top_occasions';
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.top_lists.top_occasions.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.top_lists.top_occasions.description';
    }

    #[\Override]
    public function icon(): string
    {
        return 'tabler:affiliate';
    }

    #[\Override]
    public function catalogDimension(): CatalogDimensionKey
    {
        return CatalogDimensionKey::Occasion;
    }

    #[\Override]
    protected function projectionJoinProperty(): string
    {
        return 'occasionId';
    }

    #[\Override]
    protected function entityFqcn(): string
    {
        return Occasion::class;
    }

    #[\Override]
    public function tableLabelColumnTranslationKey(): string
    {
        return 'stats.top_lists.table.occasion';
    }
}
