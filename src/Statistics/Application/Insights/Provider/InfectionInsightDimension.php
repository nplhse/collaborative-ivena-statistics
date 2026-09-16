<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights\Provider;

use App\Allocation\Domain\Entity\Infection;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightNavPlacement;

final readonly class InfectionInsightDimension extends AbstractEntityInsightDimension
{
    #[\Override]
    public function key(): InsightDimensionKey
    {
        return InsightDimensionKey::Infections;
    }

    #[\Override]
    public function entityFqcn(): string
    {
        return Infection::class;
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.insights.dimension.infections.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.insights.dimension.infections.description';
    }

    #[\Override]
    public function allValuesLinkTranslationKey(): string
    {
        return 'stats.insights.dimension.infections.all';
    }

    #[\Override]
    public function icon(): string
    {
        return 'tabler:virus';
    }

    #[\Override]
    public function navPlacement(): InsightNavPlacement
    {
        return InsightNavPlacement::Primary;
    }

    #[\Override]
    public function navOrder(): int
    {
        return 60;
    }

    #[\Override]
    public function disabledInsightIds(): array
    {
        return ['infectious'];
    }
}
