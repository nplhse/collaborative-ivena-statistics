<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Query;

final readonly class IndicationRawReviewHealthCheckResult
{
    public function __construct(
        public string $id,
        public string $label,
        public int $count,
        public IndicationRawReviewHealthCheckSeverity $severity,
        public string $hint = '',
    ) {
    }

    public function isFailing(): bool
    {
        return IndicationRawReviewHealthCheckSeverity::Fail === $this->severity && $this->count > 0;
    }

    public function statusLabel(): string
    {
        if (IndicationRawReviewHealthCheckSeverity::Info === $this->severity) {
            return 'INFO';
        }

        if (0 === $this->count) {
            return 'OK';
        }

        return match ($this->severity) {
            IndicationRawReviewHealthCheckSeverity::Warn => 'WARN',
            IndicationRawReviewHealthCheckSeverity::Fail => 'FAIL',
        };
    }
}
