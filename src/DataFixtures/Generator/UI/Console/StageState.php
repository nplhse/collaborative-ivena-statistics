<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\UI\Console;

/**
 * State of one stage in the demo wizard's live view.
 */
enum StageState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Background = 'background';

    public function marker(): string
    {
        return match ($this) {
            self::Pending => '<fg=gray>○</>',
            self::Running => '<fg=yellow>◐</>',
            self::Completed => '<fg=green>✔</>',
            self::Failed => '<fg=red>✘</>',
            self::Skipped => '<fg=gray>–</>',
            self::Background => '<fg=cyan>↻</>',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Background => 'in worker',
            default => $this->value,
        };
    }
}
