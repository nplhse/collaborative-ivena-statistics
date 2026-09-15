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
     *     analysisLevel: 'hospital',
     *     layers: list<'isochroneBands'|'hospitalPin'>,
     *     compactLayers: list<'isochroneBands'|'hospitalPin'>,
     *     expandedLayers: list<'isochroneBands'|'hospitalPin'>,
     *     hospital: array{name: string, lat: float, lng: float},
     *     bands: list<array{
     *         minutes: int,
     *         count: int,
     *         share: float,
     *         intensity: float,
     *         geometry: array<string, mixed>,
     *         label: string
     *     }>,
     *     isochrone: array{
     *         bands: list<array{
     *             minutes: int,
     *             count: int,
     *             share: float,
     *             intensity: float,
     *             geometry: array<string, mixed>,
     *             label: string
     *         }>,
     *         unknownCount: int,
     *         beyondMaxCount: int
     *     }
     * }
     */
    public function mapPayload(): array
    {
        $bands = [];
        foreach ($this->bands as $band) {
            $bands[] = $band->toMapPayload();
        }

        return [
            'analysisLevel' => 'hospital',
            'layers' => ['isochroneBands', 'hospitalPin'],
            'compactLayers' => ['isochroneBands', 'hospitalPin'],
            'expandedLayers' => ['isochroneBands', 'hospitalPin'],
            'hospital' => [
                'name' => $this->hospitalName,
                'lat' => $this->latitude,
                'lng' => $this->longitude,
            ],
            'bands' => $bands,
            'isochrone' => [
                'bands' => $bands,
                'unknownCount' => $this->unknownCount,
                'beyondMaxCount' => $this->beyondMaxCount,
            ],
        ];
    }
}
