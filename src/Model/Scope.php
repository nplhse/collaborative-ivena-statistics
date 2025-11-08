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

    public static function fromDashboardContext(DashboardContext $context): self
    {
        $scopeType = $context->scopeType ?: 'public';
        $scopeId = $context->scopeId ?: 'all';
        $granularity = $context->granularity ?: 'year';
        $periodKey = $context->periodKey ?: sprintf('%d-01-01', (int) date('Y'));

        if ('month' === $granularity && preg_match('/^\d{4}-\d{2}/', $periodKey)) {
            $periodKey = substr($periodKey, 0, 7).'-01';
        }

        if ('all' === $granularity) {
            $periodKey = '2010-01-01';
        }

        return new self($scopeType, $scopeId, $granularity, $periodKey);
    }
}
