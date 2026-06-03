<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\DTO;

final readonly class MetricCompatibilityResult
{
    public function __construct(
        public bool $allowed,
        public ?string $reason = null,
    ) {
    }

    public static function allowed(): self
    {
        return new self(allowed: true);
    }

    public static function denied(?string $reason = null): self
    {
        return new self(allowed: false, reason: $reason);
    }
}
