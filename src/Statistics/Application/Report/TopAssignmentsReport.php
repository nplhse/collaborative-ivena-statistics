<?php

declare(strict_types=1);

namespace App\Statistics\Application\Report;

use App\Allocation\Domain\Entity\Assignment;

final readonly class TopAssignmentsReport extends AbstractTopNTableReport
{
    #[\Override]
    public function key(): string
    {
        return 'top_assignments';
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.reports.top_assignments.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.reports.top_assignments.description';
    }

    #[\Override]
    protected function projectionJoinProperty(): string
    {
        return 'assignmentId';
    }

    #[\Override]
    protected function entityFqcn(): string
    {
        return Assignment::class;
    }

    #[\Override]
    protected function tableLabelColumnTranslationKey(): string
    {
        return 'stats.reports.table.assignment';
    }
}
