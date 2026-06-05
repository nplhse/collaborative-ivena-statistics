<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Exception;

final class UnknownAnalysisViewException extends \RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('Unknown analysis view: "%s".', $key));
    }
}
