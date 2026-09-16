<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights\Provider;

use App\Allocation\Domain\Entity\Occasion;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightNavPlacement;

final readonly class OccasionInsightDimension extends AbstractEntityInsightDimension
{
    #[\Override]
    public function key(): InsightDimensionKey
    {
        return InsightDimensionKey::Occasions;
    }

    #[\Override]
    public function entityFqcn(): string
    {
        return Occasion::class;
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.insights.dimension.occasions.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.insights.dimension.occasions.description';
    }

    #[\Override]
    public function allValuesLinkTranslationKey(): string
    {
        return 'stats.insights.dimension.occasions.all';
    }

    #[\Override]
    public function icon(): string
    {
        return 'tabler:flag';
    }

    #[\Override]
    public function navPlacement(): InsightNavPlacement
    {
        return InsightNavPlacement::Primary;
    }

    #[\Override]
    public function navOrder(): int
    {
        return 50;
    }
}
