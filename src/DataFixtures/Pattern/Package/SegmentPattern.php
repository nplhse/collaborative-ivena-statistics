<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Package;

/**
 * The distributions of one segment (ADR 022): indication mix, occasions, assignments, infections,
 * secondary transports, transport types, hour of day, base flags and transport time percentiles.
 */
final readonly class SegmentPattern
{
    /**
     * @param array<array-key, float> $occasions            occasion name → share
     * @param array<array-key, float> $assignments          assignment name → share
     * @param array<array-key, float> $transportTypes       transport type (G, A) → share
     * @param array<array-key, float> $hours                hour 0–23 → share
     * @param array<array-key, float> $flags                flag → probability
     * @param array<string, float>    $transportTimeMinutes percentile (p25, p50, p75, p90) → minutes
     */
    public function __construct(
        public OptionalShares $indications,
        public array $occasions,
        public array $assignments,
        public OptionalShares $infections,
        public OptionalShares $secondaryTransports,
        public array $transportTypes,
        public array $hours,
        public array $flags,
        public array $transportTimeMinutes,
    ) {
    }

    /**
     * @param array<string, mixed> $data a segment that passed the structure check
     */
    public static function fromArray(array $data): self
    {
        /* @var array{indications: array{present: float|int, shares: array<array-key, float|int>}, occasions: array<array-key, float|int>, assignments: array<array-key, float|int>, infections: array{present: float|int, shares: array<array-key, float|int>}, secondary_transports: array{present: float|int, shares: array<array-key, float|int>}, transport_types: array<array-key, float|int>, hours: array<array-key, float|int>, flags: array<array-key, float|int>, transport_time_minutes: array<string, float|int>} $data */
        return new self(
            OptionalShares::fromArray($data['indications']),
            array_map(floatval(...), $data['occasions']),
            array_map(floatval(...), $data['assignments']),
            OptionalShares::fromArray($data['infections']),
            OptionalShares::fromArray($data['secondary_transports']),
            array_map(floatval(...), $data['transport_types']),
            array_map(floatval(...), $data['hours']),
            array_map(floatval(...), $data['flags']),
            array_map(floatval(...), $data['transport_time_minutes']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'indications' => $this->indications->toArray(),
            'occasions' => $this->occasions,
            'assignments' => $this->assignments,
            'infections' => $this->infections->toArray(),
            'secondary_transports' => $this->secondaryTransports->toArray(),
            'transport_types' => $this->transportTypes,
            'hours' => $this->hours,
            'flags' => $this->flags,
            'transport_time_minutes' => $this->transportTimeMinutes,
        ];
    }
}
