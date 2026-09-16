<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights\Provider;

use App\Allocation\Domain\Entity\Department;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightNavPlacement;

final readonly class DepartmentInsightDimension extends AbstractEntityInsightDimension
{
    #[\Override]
    public function key(): InsightDimensionKey
    {
        return InsightDimensionKey::Departments;
    }

    #[\Override]
    public function entityFqcn(): string
    {
        return Department::class;
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.insights.dimension.departments.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.insights.dimension.departments.description';
    }

    #[\Override]
    public function allValuesLinkTranslationKey(): string
    {
        return 'stats.insights.dimension.departments.all';
    }

    #[\Override]
    public function icon(): string
    {
        return 'tabler:building';
    }

    #[\Override]
    public function navPlacement(): InsightNavPlacement
    {
        return InsightNavPlacement::Primary;
    }

    #[\Override]
    public function navOrder(): int
    {
        return 40;
    }
}
