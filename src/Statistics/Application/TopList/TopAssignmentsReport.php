<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

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
        return 'stats.top_lists.top_assignments.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.top_lists.top_assignments.description';
    }

    #[\Override]
    public function icon(): string
    {
        return 'tabler:archery-arrow';
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
    public function tableLabelColumnTranslationKey(): string
    {
        return 'stats.top_lists.table.assignment';
    }
}
