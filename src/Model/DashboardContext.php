<?php

declare(strict_types=1);

namespace App\Model;

final readonly class DashboardContext
{
    public function __construct(
        public string $scopeType,
        public string $scopeId,
        public string $granularity,
        public string $periodKey,
    ) {
        self::assertValid($scopeType, $granularity);
    }

    private static function assertValid(string $scopeType, string $granularity): void
    {
        $validScopes = [
            'public', 'state', 'dispatch_area', 'hospital',
            'hospital_tier', 'hospital_size', 'hospital_location', 'hospital_cohort',
        ];

        $validGranularities = ['all', 'year', 'quarter', 'month', 'week', 'day'];

        if (!\in_array($scopeType, $validScopes, true)) {
            throw new \InvalidArgumentException("Invalid scopeType: {$scopeType}");
        }
        if (!\in_array($granularity, $validGranularities, true)) {
            throw new \InvalidArgumentException("Invalid granularity: {$granularity}");
        }
    }

    /**
     * @param array{
     *   scopeType?: string,
     *   scopeId?: string,
     *   gran?: string,
     *   key?: string
     * } $query
     */
    public static function fromQuery(array $query): self
    {
        return new self(
            $query['scopeType'] ?? 'public',
            $query['scopeId'] ?? 'all',
            $query['gran'] ?? 'all',
            $query['key'] ?? '2010-01-01',
        );
    }

    /**
     * @return array<string, string|int|float|bool|null>
     */
    public function toQuery(): array
    {
        return [
            'scopeType' => $this->scopeType,
            'scopeId' => $this->scopeId,
            'gran' => $this->granularity,
            'key' => $this->periodKey,
        ];
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function with(?string $granularity = null, ?string $periodKey = null): self
    {
        return new self(
            $this->scopeType,
            $this->scopeId,
            $granularity ?? $this->granularity,
            $periodKey ?? $this->periodKey
        );
    }
}
