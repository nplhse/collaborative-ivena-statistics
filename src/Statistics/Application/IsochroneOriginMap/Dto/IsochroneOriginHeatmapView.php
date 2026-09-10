<?php

declare(strict_types=1);

namespace App\Statistics\Application\IsochroneOriginMap\Dto;

final readonly class IsochroneOriginHeatmapView
{
    /**
     * @param list<IsochroneOriginBandView> $bands
     */
    public function __construct(
        public string $hospitalName,
        public float $latitude,
        public float $longitude,
        public array $bands,
        public int $unknownCount,
        public int $beyondMaxCount,
        public int $totalCount,
        public int $maxBandCount,
    ) {
    }

    /**
     * @return array{
     *     hospital: array{name: string, lat: float, lng: float},
     *     bands: list<array{
     *         minutes: int,
     *         count: int,
     *         share: float,
     *         intensity: float,
     *         geometry: array<string, mixed>,
     *         label: string
     *     }>
     * }
     */
    public function mapPayload(): array
    {
        $bands = [];
        foreach ($this->bands as $band) {
            $bands[] = $band->toMapPayload();
        }

        return [
            'hospital' => [
                'name' => $this->hospitalName,
                'lat' => $this->latitude,
                'lng' => $this->longitude,
            ],
            'bands' => $bands,
        ];
    }
}
