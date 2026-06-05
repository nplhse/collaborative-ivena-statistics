<?php

declare(strict_types=1);

namespace App\Statistics\Application\Report;

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
        return 'stats.reports.top_departments.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.reports.top_departments.description';
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
    protected function tableLabelColumnTranslationKey(): string
    {
        return 'stats.reports.table.department';
    }
}
