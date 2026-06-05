<?php

declare(strict_types=1);

namespace App\Statistics\Application\Report;

use App\Allocation\Domain\Entity\Speciality;

final readonly class TopSpecialitiesReport extends AbstractTopNTableReport
{
    #[\Override]
    public function key(): string
    {
        return 'top_specialities';
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.reports.top_specialities.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.reports.top_specialities.description';
    }

    #[\Override]
    protected function projectionJoinProperty(): string
    {
        return 'specialityId';
    }

    #[\Override]
    protected function entityFqcn(): string
    {
        return Speciality::class;
    }

    #[\Override]
    protected function tableLabelColumnTranslationKey(): string
    {
        return 'stats.reports.table.speciality';
    }
}
