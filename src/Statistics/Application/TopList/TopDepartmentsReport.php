<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Allocation\Domain\Entity\Department;

final readonly class TopDepartmentsReport extends AbstractTopNTableReport
{
    #[\Override]
    public function key(): string
    {
        return 'top_departments';
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.top_lists.top_departments.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.top_lists.top_departments.description';
    }

    #[\Override]
    public function icon(): string
    {
        return 'tabler:folder-dollar';
    }

    #[\Override]
    public function catalogDimension(): CatalogDimensionKey
    {
        return CatalogDimensionKey::Department;
    }

    #[\Override]
    protected function projectionJoinProperty(): string
    {
        return 'departmentId';
    }

    #[\Override]
    protected function entityFqcn(): string
    {
        return Department::class;
    }

    #[\Override]
    public function tableLabelColumnTranslationKey(): string
    {
        return 'stats.top_lists.table.department';
    }
}
