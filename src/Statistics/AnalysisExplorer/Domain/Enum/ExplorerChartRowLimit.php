<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

enum ExplorerChartRowLimit: string
{
    case Top5 = '5';
    case Top10 = '10';
    case All = 'all';

    public function cap(): ?int
    {
        return match ($this) {
            self::Top5 => 5,
            self::Top10 => 10,
            self::All => null,
        };
    }

    public static function fromValue(string $value): self
    {
        return match ($value) {
            self::Top10->value => self::Top10,
            self::Top5->value => self::Top5,
            default => self::All,
        };
    }
}
