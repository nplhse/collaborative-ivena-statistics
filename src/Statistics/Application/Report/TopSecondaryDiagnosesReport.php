<?php

declare(strict_types=1);

namespace App\Statistics\Application\Report;

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
        return 'stats.reports.top_secondary_diagnoses.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.reports.top_secondary_diagnoses.description';
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
    protected function tableLabelColumnTranslationKey(): string
    {
        return 'stats.reports.table.secondary_diagnosis';
    }
}
