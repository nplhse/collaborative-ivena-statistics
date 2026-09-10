<?php

declare(strict_types=1);

namespace App\Statistics\Application\IsochroneOriginMap\Dto;

final readonly class IsochroneOriginBandView
{
    /**
     * @param array<string, mixed> $geometry GeoJSON geometry
     */
    public function __construct(
        public int $minutes,
        public int $count,
        public float $share,
        public float $intensity,
        public array $geometry,
        public string $label,
    ) {
    }

    /**
     * @return array{
     *     minutes: int,
     *     count: int,
     *     share: float,
     *     intensity: float,
     *     geometry: array<string, mixed>,
     *     label: string
     * }
     */
    public function toMapPayload(): array
    {
        return [
            'minutes' => $this->minutes,
            'count' => $this->count,
            'share' => $this->share,
            'intensity' => $this->intensity,
            'geometry' => $this->geometry,
            'label' => $this->label,
        ];
    }
}
