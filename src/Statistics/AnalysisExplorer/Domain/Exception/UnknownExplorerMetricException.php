<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Exception;

final class UnknownExplorerMetricException extends \InvalidArgumentException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('Unknown explorer metric key "%s".', $key));
    }
}
