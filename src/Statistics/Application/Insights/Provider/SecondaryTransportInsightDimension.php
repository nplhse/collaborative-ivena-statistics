<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights\Provider;

use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightNavPlacement;

final readonly class SecondaryTransportInsightDimension extends AbstractEntityInsightDimension
{
    #[\Override]
    public function key(): InsightDimensionKey
    {
        return InsightDimensionKey::SecondaryTransports;
    }

    #[\Override]
    public function entityFqcn(): string
    {
        return SecondaryTransport::class;
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.insights.dimension.secondary_transports.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.insights.dimension.secondary_transports.description';
    }

    #[\Override]
    public function allValuesLinkTranslationKey(): string
    {
        return 'stats.insights.dimension.secondary_transports.all';
    }

    #[\Override]
    public function icon(): string
    {
        return 'tabler:transfer';
    }

    #[\Override]
    public function navPlacement(): InsightNavPlacement
    {
        return InsightNavPlacement::Primary;
    }

    #[\Override]
    public function navOrder(): int
    {
        return 70;
    }
}
