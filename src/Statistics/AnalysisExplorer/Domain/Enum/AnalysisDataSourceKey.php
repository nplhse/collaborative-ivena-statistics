<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

enum AnalysisDataSourceKey: string
{
    case Allocations = 'allocations';
    case Hospitals = 'hospitals';

    public function labelTranslationKey(): string
    {
        return match ($this) {
            self::Allocations => 'stats.analysis_explorer.data_source.allocations',
            self::Hospitals => 'stats.analysis_explorer.data_source.hospitals',
        };
    }

    public function contextDescriptionTranslationKey(): string
    {
        return match ($this) {
            self::Allocations => 'stats.analysis_explorer.data_source.context.allocations',
            self::Hospitals => 'stats.analysis_explorer.data_source.context.hospitals',
        };
    }
}
