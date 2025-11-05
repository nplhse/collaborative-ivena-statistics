<?php

declare(strict_types=1);

namespace App\Model;

final class Scope
{
    public function __construct(
        public string $scopeType,
        public string $scopeId,
        public string $granularity,
        public string $periodKey,
    ) {
    }

    public function lockKey(): string
    {
        return sprintf('agg:%s:%s:%s:%s',
            $this->scopeType,
            $this->scopeId,
            $this->granularity,
            $this->periodKey
        );
    }

    public function isHospital(): bool
    {
        return 'hospital' === $this->scopeType;
    }
}
