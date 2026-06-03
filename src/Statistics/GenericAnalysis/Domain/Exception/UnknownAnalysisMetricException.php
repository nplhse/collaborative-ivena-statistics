<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Exception;

final class UnknownAnalysisMetricException extends \InvalidArgumentException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('Unknown analysis metric: "%s".', $key));
    }
}
