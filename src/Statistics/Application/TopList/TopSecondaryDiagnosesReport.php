<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Allocation\Domain\Entity\IndicationNormalized;

final readonly class TopSecondaryDiagnosesReport extends AbstractTopNTableReport
{
    #[\Override]
    public function key(): string
    {
        return 'top_secondary_diagnoses';
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.top_lists.top_secondary_diagnoses.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.top_lists.top_secondary_diagnoses.description';
    }

    #[\Override]
    public function icon(): string
    {
        return 'tabler:id';
    }

    #[\Override]
    public function catalogDimension(): CatalogDimensionKey
    {
        return CatalogDimensionKey::Indication;
    }

    #[\Override]
    protected function projectionJoinProperty(): string
    {
        return 'secondaryIndicationNormalizedId';
    }

    #[\Override]
    protected function entityFqcn(): string
    {
        return IndicationNormalized::class;
    }

    #[\Override]
    public function tableLabelColumnTranslationKey(): string
    {
        return 'stats.top_lists.table.secondary_diagnosis';
    }

    #[\Override]
    protected function requireJoinedEntity(): bool
    {
        return true;
    }
}
