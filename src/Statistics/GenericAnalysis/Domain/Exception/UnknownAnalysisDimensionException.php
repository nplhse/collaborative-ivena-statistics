<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Exception;

final class UnknownAnalysisDimensionException extends \InvalidArgumentException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('Unknown analysis dimension: "%s".', $key));
    }

    public static function notAllowedForScope(string $key): self
    {
        return new self(sprintf('Analysis dimension "%s" is not allowed for the current scope.', $key));
    }
}
