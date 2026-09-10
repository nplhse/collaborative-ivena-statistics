<?php

declare(strict_types=1);

namespace App\Statistics\Application\IsochroneOriginMap;

use App\Allocation\Application\Contracts\HospitalIsochroneProviderInterface;
use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Domain\Entity\Hospital;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\IsochroneOriginMap\Dto\IsochroneOriginBandView;
use App\Statistics\Application\IsochroneOriginMap\Dto\IsochroneOriginHeatmapView;
use App\Statistics\Application\Mapping\IsochroneOriginBandSql;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class IsochroneOriginHeatmapAssembler
{
    public function __construct(
        private HospitalLookupInterface $hospitalLookup,
        private HospitalIsochroneProviderInterface $isochroneProvider,
        private IsochroneOriginBandQueryInterface $bandQuery,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<int>|null $indicationIds
     */
    public function build(
        StatisticsFilter $filter,
        StatisticsScopeCriteria $scope,
        StatisticsPeriodBounds $period,
        ?array $indicationIds = null,
    ): ?IsochroneOriginHeatmapView {
        if (StatisticsFilterScope::Hospital !== $filter->scope || null === $filter->hospitalId) {
            return null;
        }

        $hospital = $this->hospitalLookup->findById($filter->hospitalId);
        if (!$hospital instanceof Hospital) {
            return null;
        }

        $latitude = $hospital->getLatitude();
        $longitude = $hospital->getLongitude();
        if (null === $latitude || null === $longitude) {
            return null;
        }

        $catalog = $this->isochroneProvider->findForHospital($hospital);
        if (null === $catalog) {
            return null;
        }

        $geometriesByMinutes = $this->geometriesByMinutes($catalog['features']);
        if ([] === $geometriesByMinutes) {
            return null;
        }

        $counts = $this->bandQuery->fetch($period->from, $period->toExclusive, $scope, $indicationIds);
        $total = $counts->total();
        $maxBandCount = 0;
        foreach (IsochroneOriginBandSql::DISPLAY_BAND_MINUTES as $minutes) {
            if (!isset($geometriesByMinutes[$minutes])) {
                continue;
            }

            $maxBandCount = max($maxBandCount, $counts->countFor((string) $minutes));
        }

        $bands = [];
        foreach (IsochroneOriginBandSql::DISPLAY_BAND_MINUTES as $minutes) {
            $geometry = $geometriesByMinutes[$minutes] ?? null;
            if (!\is_array($geometry)) {
                continue;
            }

            $count = $counts->countFor((string) $minutes);
            $share = $total > 0 ? $count / $total : 0.0;
            $intensity = $maxBandCount > 0 ? $count / $maxBandCount : 0.0;
            $fromMinutes = $minutes - IsochroneOriginBandSql::INTERVAL_MINUTES;
            $bands[] = new IsochroneOriginBandView(
                $minutes,
                $count,
                $share,
                $intensity,
                $geometry,
                $this->translator->trans(
                    'stats.isochrone_origin_map.band',
                    ['from' => $fromMinutes, 'to' => $minutes],
                    'statistics',
                ),
            );
        }

        if ([] === $bands) {
            return null;
        }

        return new IsochroneOriginHeatmapView(
            $hospital->getName() ?? '',
            $latitude,
            $longitude,
            $bands,
            $counts->countFor(IsochroneOriginBandSql::UNKNOWN),
            $counts->countFor(IsochroneOriginBandSql::BEYOND_MAX),
            $total,
            $maxBandCount,
        );
    }

    /**
     * @param list<array<string, mixed>> $features
     *
     * @return array<int, array<string, mixed>>
     */
    private function geometriesByMinutes(array $features): array
    {
        $out = [];
        foreach ($features as $feature) {
            $properties = $feature['properties'] ?? null;
            $value = \is_array($properties) ? ($properties['value'] ?? null) : null;
            $geometry = $feature['geometry'] ?? null;
            if (!is_numeric($value) || !\is_array($geometry)) {
                continue;
            }

            $seconds = (int) $value;
            if (0 !== $seconds % 60) {
                continue;
            }

            $minutes = intdiv($seconds, 60);
            if (!\in_array($minutes, IsochroneOriginBandSql::DISPLAY_BAND_MINUTES, true)) {
                continue;
            }

            $out[$minutes] = $geometry;
        }

        return $out;
    }
}
