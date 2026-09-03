<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Allocation\Domain\Entity\SecondaryTransport;

final readonly class TopSecondaryTransportsReport extends AbstractTopNTableReport
{
    #[\Override]
    public function key(): string
    {
        return 'top_secondary_transports';
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.top_lists.top_secondary_transports.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.top_lists.top_secondary_transports.description';
    }

    #[\Override]
    public function icon(): string
    {
        return 'tabler:transfer';
    }

    #[\Override]
    public function catalogDimension(): CatalogDimensionKey
    {
        return CatalogDimensionKey::SecondaryTransport;
    }

    #[\Override]
    protected function projectionJoinProperty(): string
    {
        return 'secondaryTransportId';
    }

    #[\Override]
    protected function entityFqcn(): string
    {
        return SecondaryTransport::class;
    }

    #[\Override]
    public function tableLabelColumnTranslationKey(): string
    {
        return 'stats.top_lists.table.secondary_transport';
    }

    #[\Override]
    protected function requireJoinedEntity(): bool
    {
        return true;
    }
}
