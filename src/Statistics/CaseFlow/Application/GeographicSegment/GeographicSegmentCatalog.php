<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\GeographicSegment;

final readonly class GeographicSegmentCatalog
{
    /**
     * @param list<GeographicSegmentOption> $options
     */
    public function __construct(
        public array $options,
    ) {
    }

    /**
     * @return list<GeographicSegmentOption>
     */
    public function pickerOptions(): array
    {
        return array_values(array_filter(
            $this->options,
            static fn (GeographicSegmentOption $option): bool => $option->showInPicker,
        ));
    }

    /**
     * @return list<GeographicSegmentOption>
     */
    public function pickerOptionsOfType(GeographicSegmentType $type): array
    {
        return array_values(array_filter(
            $this->pickerOptions(),
            static fn (GeographicSegmentOption $option): bool => $option->segment->type === $type,
        ));
    }

    /**
     * @return list<GeographicSegmentOption>
     */
    public function pickerOriginOptions(): array
    {
        return $this->pickerOptionsOfType(GeographicSegmentType::OriginArea);
    }

    /**
     * @return list<GeographicSegmentOption>
     */
    public function pickerTravelOptions(): array
    {
        return $this->pickerOptionsOfType(GeographicSegmentType::TravelTimeBand);
    }

    public function find(GeographicSegment $segment): ?GeographicSegmentOption
    {
        foreach ($this->options as $option) {
            if ($option->segment->type === $segment->type && $option->segment->id === $segment->id) {
                return $option;
            }
        }

        return null;
    }
}
