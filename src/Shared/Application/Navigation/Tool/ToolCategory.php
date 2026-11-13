<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Tool;

/**
 * Groups on the Tools landing page, shown in the order of the cases.
 */
enum ToolCategory: string
{
    case Matching = 'matching';
    case DataPreparation = 'data_preparation';

    public function labelKey(): string
    {
        return 'tools.category.'.$this->value;
    }

    public function position(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
