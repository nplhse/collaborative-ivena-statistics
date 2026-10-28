<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\PreparedLocation;

use App\Allocation\Application\Hospital\HospitalGeocodeAddress;
use App\Allocation\Application\Hospital\IsochroneOrigin;

/**
 * A geographic location prepared online (coordinates and destination isochrones) that can be
 * applied to a hospital later without network access (#791).
 *
 * @psalm-immutable
 */
final readonly class PreparedLocation
{
    public const int FORMAT_VERSION = 1;

    private const string SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/';

    /**
     * @param array{type: string, features: list<array<string, mixed>>, properties?: array<string, mixed>} $isochrones
     */
    public function __construct(
        public string $slug,
        public string $label,
        public ?HospitalGeocodeAddress $address,
        public float $latitude,
        public float $longitude,
        public array $isochrones,
        public \DateTimeImmutable $preparedAt,
    ) {
        if (!self::isValidSlug($slug)) {
            throw new \InvalidArgumentException(sprintf('Invalid prepared location slug "%s". Use lowercase letters, digits and hyphens.', $slug));
        }

        if (!IsochroneOrigin::matches($isochrones, $latitude, $longitude)) {
            throw new \InvalidArgumentException(sprintf('Isochrones of prepared location "%s" were not computed for its coordinates.', $slug));
        }
    }

    public static function isValidSlug(string $slug): bool
    {
        return 1 === preg_match(self::SLUG_PATTERN, $slug);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'formatVersion' => self::FORMAT_VERSION,
            'slug' => $this->slug,
            'label' => $this->label,
            'address' => $this->address instanceof HospitalGeocodeAddress ? [
                'street' => $this->address->street,
                'postalCode' => $this->address->postalCode,
                'city' => $this->address->city,
                'country' => $this->address->country,
            ] : null,
            'coordinates' => ['lat' => $this->latitude, 'lng' => $this->longitude],
            'preparedAt' => $this->preparedAt->format(\DATE_ATOM),
            'isochrones' => $this->isochrones,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (self::FORMAT_VERSION !== ($data['formatVersion'] ?? null)) {
            throw new \InvalidArgumentException('Unsupported prepared location format version.');
        }

        $coordinates = $data['coordinates'] ?? null;
        $isochrones = $data['isochrones'] ?? null;
        if (!\is_array($coordinates) || !\is_array($isochrones)) {
            throw new \InvalidArgumentException('Prepared location is missing coordinates or isochrones.');
        }

        $preparedAt = \DateTimeImmutable::createFromFormat('!'.\DATE_ATOM, self::string($data, 'preparedAt'));
        if (!$preparedAt instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('Prepared location has an invalid "preparedAt" timestamp.');
        }

        return new self(
            slug: self::string($data, 'slug'),
            label: self::string($data, 'label'),
            address: self::address($data['address'] ?? null),
            latitude: self::float($coordinates, 'lat'),
            longitude: self::float($coordinates, 'lng'),
            isochrones: self::featureCollection($isochrones),
            preparedAt: $preparedAt,
        );
    }

    private static function address(mixed $address): ?HospitalGeocodeAddress
    {
        if (null === $address) {
            return null;
        }

        if (!\is_array($address)) {
            throw new \InvalidArgumentException('Prepared location has an invalid address.');
        }

        return new HospitalGeocodeAddress(
            self::string($address, 'street'),
            self::string($address, 'postalCode'),
            self::string($address, 'city'),
            self::string($address, 'country'),
        );
    }

    /**
     * @param array<mixed> $collection
     *
     * @return array{type: string, features: list<array<string, mixed>>, properties?: array<string, mixed>}
     */
    private static function featureCollection(array $collection): array
    {
        $features = $collection['features'] ?? null;
        if ('FeatureCollection' !== ($collection['type'] ?? null) || !\is_array($features) || [] === $features) {
            throw new \InvalidArgumentException('Prepared location isochrones are not a non-empty FeatureCollection.');
        }

        $list = [];
        foreach ($features as $feature) {
            if (!\is_array($feature)) {
                throw new \InvalidArgumentException('Prepared location isochrones contain an invalid feature.');
            }

            $list[] = self::stringKeyed($feature);
        }

        $result = ['type' => 'FeatureCollection', 'features' => $list];
        $properties = $collection['properties'] ?? null;
        if (\is_array($properties)) {
            $result['properties'] = self::stringKeyed($properties);
        }

        return $result;
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Prepared location field "%s" must be a string.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function float(array $data, string $key): float
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value) && !\is_float($value)) {
            throw new \InvalidArgumentException(sprintf('Prepared location coordinate "%s" must be a number.', $key));
        }

        return (float) $value;
    }
}
