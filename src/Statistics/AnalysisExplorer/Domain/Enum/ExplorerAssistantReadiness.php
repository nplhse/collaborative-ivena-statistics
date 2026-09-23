<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

enum ExplorerAssistantReadiness
{
    case Incomplete;
    case Ready;
    case SameAxis;
    case Unsupported;
}
