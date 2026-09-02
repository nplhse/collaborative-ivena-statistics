<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

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
        return 'stats.top_lists.top_infections.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.top_lists.top_infections.description';
    }

    #[\Override]
    public function icon(): string
    {
        return 'tabler:biohazard';
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
    public function tableLabelColumnTranslationKey(): string
    {
        return 'stats.top_lists.table.infection';
    }

    #[\Override]
    protected function requireJoinedEntity(): bool
    {
        return true;
    }
}
