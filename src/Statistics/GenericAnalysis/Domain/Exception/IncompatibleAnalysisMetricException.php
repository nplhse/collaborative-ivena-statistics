<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Exception;

final class IncompatibleAnalysisMetricException extends \InvalidArgumentException
{
    public static function forMetric(string $key, ?string $reason = null): self
    {
        $message = sprintf('Analysis metric "%s" is not compatible with the current query.', $key);
        if (null !== $reason && '' !== $reason) {
            $message .= ' '.$reason;
        }

        return new self($message);
    }
}
