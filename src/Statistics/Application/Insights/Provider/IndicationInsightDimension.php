<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights\Provider;

use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightNavPlacement;

final readonly class IndicationInsightDimension extends AbstractEntityInsightDimension
{
    #[\Override]
    public function key(): InsightDimensionKey
    {
        return InsightDimensionKey::Indications;
    }

    #[\Override]
    public function entityFqcn(): string
    {
        return IndicationNormalized::class;
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.insights.dimension.indications.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.insights.dimension.indications.description';
    }

    #[\Override]
    public function allValuesLinkTranslationKey(): string
    {
        return 'stats.insights.dimension.indications.all';
    }

    #[\Override]
    public function icon(): string
    {
        return 'tabler:stethoscope';
    }

    #[\Override]
    public function navPlacement(): InsightNavPlacement
    {
        return InsightNavPlacement::Primary;
    }

    #[\Override]
    public function navOrder(): int
    {
        return 10;
    }

    #[\Override]
    public function featuredOnOverview(): bool
    {
        return true;
    }

    #[\Override]
    public function hasCode(): bool
    {
        return true;
    }
}
