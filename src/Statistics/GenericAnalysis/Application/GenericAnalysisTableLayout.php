<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

enum GenericAnalysisTableLayout: string
{
    case Stacked = 'stacked';
    case Grouped = 'grouped';

    public static function fromRequestValue(?string $value): self
    {
        if (self::Grouped->value === $value) {
            return self::Grouped;
        }

        return self::Stacked;
    }
}
