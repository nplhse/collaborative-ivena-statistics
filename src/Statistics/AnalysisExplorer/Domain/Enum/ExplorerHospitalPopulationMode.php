<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

enum ExplorerHospitalPopulationMode: string
{
    case All = 'all';
    case Participating = 'participating';
    case Compare = 'compare';

    public function labelTranslationKey(): string
    {
        return match ($this) {
            self::All => 'stats.analysis_explorer.hospital_population.all',
            self::Participating => 'stats.analysis_explorer.hospital_population.participating',
            self::Compare => 'stats.analysis_explorer.hospital_population.compare',
        };
    }
}
