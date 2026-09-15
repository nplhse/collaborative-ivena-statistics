<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\GeographicSegment;

use App\Statistics\Application\Mapping\StatisticsTransportTimeBucketSql;

final readonly class GeographicSegment
{
    public const string QUERY_ORIGIN_PREFIX = 'origin';

    public const string QUERY_TRAVEL_PREFIX = 'travel';

    /** @var list<string> */
    public const array TRAVEL_BAND_IDS = [
        'under_10',
        '10_20',
        '20_30',
        '30_40',
        '40_50',
        'beyond_max',
        'unknown',
    ];

    public function __construct(
        public GeographicSegmentType $type,
        public string $id,
    ) {
    }

    public function toQueryValue(): string
    {
        $prefix = GeographicSegmentType::OriginArea === $this->type
            ? self::QUERY_ORIGIN_PREFIX
            : self::QUERY_TRAVEL_PREFIX;

        return $prefix.':'.$this->id;
    }

    /**
     * @return array{type: string, id: string}
     */
    public function toMapPayload(): array
    {
        return [
            'type' => $this->type->value,
            'id' => $this->id,
        ];
    }

    public static function originArea(int $dispatchAreaId): self
    {
        return new self(GeographicSegmentType::OriginArea, (string) $dispatchAreaId);
    }

    public static function travelTimeBand(string $bandId): self
    {
        return new self(GeographicSegmentType::TravelTimeBand, $bandId);
    }

    public static function tryFromQueryValue(?string $raw): ?self
    {
        if (null === $raw || '' === $raw) {
            return null;
        }

        $parts = explode(':', $raw, 2);
        if (2 !== \count($parts)) {
            return null;
        }

        [$prefix, $id] = $parts;
        if ('' === $id) {
            return null;
        }

        if (self::QUERY_ORIGIN_PREFIX === $prefix) {
            if (!ctype_digit($id) || (int) $id <= 0) {
                return null;
            }

            return self::originArea((int) $id);
        }

        if (self::QUERY_TRAVEL_PREFIX === $prefix && \in_array($id, self::TRAVEL_BAND_IDS, true)) {
            return self::travelTimeBand($id);
        }

        return null;
    }

    public static function travelBandIdFromIsochroneMinutes(int $minutes): ?string
    {
        return match ($minutes) {
            10 => 'under_10',
            20 => '10_20',
            30 => '20_30',
            40 => '30_40',
            50 => '40_50',
            default => null,
        };
    }

    public static function isochroneMinutesFromTravelBandId(string $bandId): ?int
    {
        return match ($bandId) {
            'under_10' => 10,
            '10_20' => 20,
            '20_30' => 30,
            '30_40' => 40,
            '40_50' => 50,
            default => null,
        };
    }

    public function originDispatchAreaId(): ?int
    {
        if (GeographicSegmentType::OriginArea !== $this->type) {
            return null;
        }

        $id = (int) $this->id;

        return $id > 0 ? $id : null;
    }

    public function isOriginArea(): bool
    {
        return GeographicSegmentType::OriginArea === $this->type;
    }

    public function isTravelTimeBand(): bool
    {
        return GeographicSegmentType::TravelTimeBand === $this->type;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function sqlPredicate(string $tableAlias = 'asp'): array
    {
        $prefix = '' === $tableAlias ? '' : $tableAlias.'.';

        if (GeographicSegmentType::OriginArea === $this->type) {
            return [
                sprintf('%sdispatch_area_id = :geo_segment_origin_id', $prefix),
                ['geo_segment_origin_id' => (int) $this->id],
            ];
        }

        $minutes = $prefix.'transport_time_minutes';

        return match ($this->id) {
            'unknown' => [
                sprintf('(%1$s IS NULL OR %1$s < 0)', $minutes),
                [],
            ],
            'beyond_max' => [
                sprintf('%s >= :geo_segment_travel_min', $minutes),
                ['geo_segment_travel_min' => 50],
            ],
            'under_10' => [
                sprintf('%1$s >= :geo_segment_travel_min AND %1$s < :geo_segment_travel_max', $minutes),
                ['geo_segment_travel_min' => 0, 'geo_segment_travel_max' => 10],
            ],
            '10_20' => [
                sprintf('%1$s >= :geo_segment_travel_min AND %1$s < :geo_segment_travel_max', $minutes),
                ['geo_segment_travel_min' => 10, 'geo_segment_travel_max' => 20],
            ],
            '20_30' => [
                sprintf('%1$s >= :geo_segment_travel_min AND %1$s < :geo_segment_travel_max', $minutes),
                ['geo_segment_travel_min' => 20, 'geo_segment_travel_max' => 30],
            ],
            '30_40' => [
                sprintf('%1$s >= :geo_segment_travel_min AND %1$s < :geo_segment_travel_max', $minutes),
                ['geo_segment_travel_min' => 30, 'geo_segment_travel_max' => 40],
            ],
            '40_50' => [
                sprintf('%1$s >= :geo_segment_travel_min AND %1$s < :geo_segment_travel_max', $minutes),
                ['geo_segment_travel_min' => 40, 'geo_segment_travel_max' => 50],
            ],
            default => ['1 = 0', []],
        };
    }

    public function labelTranslationKey(): ?string
    {
        if (GeographicSegmentType::TravelTimeBand !== $this->type) {
            return null;
        }

        if ('beyond_max' === $this->id) {
            return 'stats.case_flow.segment.travel.beyond_max';
        }

        if ('unknown' === $this->id) {
            return StatisticsTransportTimeBucketSql::translationKey('unknown');
        }

        return StatisticsTransportTimeBucketSql::translationKey($this->id);
    }
}
