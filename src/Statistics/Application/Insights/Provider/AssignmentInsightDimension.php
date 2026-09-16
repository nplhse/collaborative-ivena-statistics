<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights\Provider;

use App\Allocation\Domain\Entity\Assignment;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightNavPlacement;

final readonly class AssignmentInsightDimension extends AbstractEntityInsightDimension
{
    #[\Override]
    public function key(): InsightDimensionKey
    {
        return InsightDimensionKey::Assignments;
    }

    #[\Override]
    public function entityFqcn(): string
    {
        return Assignment::class;
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.insights.dimension.assignments.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.insights.dimension.assignments.description';
    }

    #[\Override]
    public function allValuesLinkTranslationKey(): string
    {
        return 'stats.insights.dimension.assignments.all';
    }

    #[\Override]
    public function icon(): string
    {
        return 'tabler:ambulance';
    }

    #[\Override]
    public function navPlacement(): InsightNavPlacement
    {
        return InsightNavPlacement::Primary;
    }

    #[\Override]
    public function navOrder(): int
    {
        return 30;
    }
}
