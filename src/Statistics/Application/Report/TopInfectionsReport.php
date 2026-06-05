<?php

declare(strict_types=1);

namespace App\Statistics\Application\Report;

use App\Allocation\Domain\Entity\Infection;

final readonly class TopInfectionsReport extends AbstractTopNTableReport
{
    #[\Override]
    public function key(): string
    {
        return 'top_infections';
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.reports.top_infections.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.reports.top_infections.description';
    }

    #[\Override]
    protected function projectionJoinProperty(): string
    {
        return 'infectionId';
    }

    #[\Override]
    protected function entityFqcn(): string
    {
        return Infection::class;
    }

    #[\Override]
    protected function tableLabelColumnTranslationKey(): string
    {
        return 'stats.reports.table.infection';
    }
}
