<?php

declare(strict_types=1);

namespace App\Statistics\Application\Report;

use App\Allocation\Domain\Entity\Occasion;

final readonly class TopOccasionsReport extends AbstractTopNTableReport
{
    #[\Override]
    public function key(): string
    {
        return 'top_occasions';
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.reports.top_occasions.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.reports.top_occasions.description';
    }

    #[\Override]
    protected function projectionJoinProperty(): string
    {
        return 'occasionId';
    }

    #[\Override]
    protected function entityFqcn(): string
    {
        return Occasion::class;
    }

    #[\Override]
    protected function tableLabelColumnTranslationKey(): string
    {
        return 'stats.reports.table.occasion';
    }
}
