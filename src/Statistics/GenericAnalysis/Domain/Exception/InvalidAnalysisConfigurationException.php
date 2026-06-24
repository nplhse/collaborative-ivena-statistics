<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Exception;

final class InvalidAnalysisConfigurationException extends \DomainException
{
    public static function withMessage(string $message): self
    {
        return new self($message);
    }
}
