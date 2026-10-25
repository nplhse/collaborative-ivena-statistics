<?php

declare(strict_types=1);

namespace App\Storage\Domain\ValueObject;

use App\Storage\Domain\Enum\MeasurementItemFlag;
use App\Storage\Domain\Enum\MeasurementItemStatus;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;

/**
 * One measured part of the storage, with both its location and its category (ADR 017, "Two views").
 * Only measured items have a size; failed and unavailable items have none.
 *
 * @psalm-type StorageMeasurementItemData = array{
 *     location: string,
 *     location_detail: string|null,
 *     category: string,
 *     contributor: string,
 *     bytes: int|null,
 *     status: string,
 *     flags: list<string>,
 * }
 */
final readonly class StorageMeasurementItem
{
    private const string CONTRIBUTOR_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';

    /**
     * @param string                    $contributor    stable identifier, e.g. "vendor" or "var_cache"
     * @param string|null               $locationDetail release name or shared path, if the location has several
     * @param list<MeasurementItemFlag> $flags
     */
    public function __construct(
        public StorageLocation $location,
        public StorageCategory $category,
        public string $contributor,
        public ?int $bytes,
        public MeasurementItemStatus $status = MeasurementItemStatus::Measured,
        public ?string $locationDetail = null,
        public array $flags = [],
    ) {
        if (1 !== preg_match(self::CONTRIBUTOR_PATTERN, $contributor)) {
            throw new \InvalidArgumentException(sprintf('Invalid storage contributor "%s".', $contributor));
        }

        if (MeasurementItemStatus::Measured === $status && (null === $bytes || $bytes < 0)) {
            throw new \InvalidArgumentException(sprintf('Measured storage item "%s" needs a size of at least 0.', $contributor));
        }

        if (MeasurementItemStatus::Measured !== $status && null !== $bytes) {
            throw new \InvalidArgumentException(sprintf('Storage item "%s" is %s and cannot have a size.', $contributor, $status->value));
        }

        if ('' === $locationDetail) {
            throw new \InvalidArgumentException('A storage location detail cannot be empty; use null instead.');
        }
    }

    /**
     * @return StorageMeasurementItemData
     */
    public function toArray(): array
    {
        return [
            'location' => $this->location->value,
            'location_detail' => $this->locationDetail,
            'category' => $this->category->value,
            'contributor' => $this->contributor,
            'bytes' => $this->bytes,
            'status' => $this->status->value,
            'flags' => array_map(static fn (MeasurementItemFlag $flag): string => $flag->value, $this->flags),
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $bytes = $data['bytes'] ?? null;
        $detail = $data['location_detail'] ?? null;
        $flags = $data['flags'] ?? [];

        if (!\is_string($data['location'] ?? null) || !\is_string($data['category'] ?? null)
            || !\is_string($data['contributor'] ?? null) || !\is_string($data['status'] ?? null)
            || (null !== $bytes && !\is_int($bytes)) || (null !== $detail && !\is_string($detail)) || !\is_array($flags)) {
            throw new \InvalidArgumentException('Malformed storage measurement item.');
        }

        return new self(
            location: StorageLocation::from($data['location']),
            category: StorageCategory::from($data['category']),
            contributor: $data['contributor'],
            bytes: $bytes,
            status: MeasurementItemStatus::from($data['status']),
            locationDetail: $detail,
            flags: array_values(array_map(static function (mixed $flag): MeasurementItemFlag {
                if (!\is_string($flag)) {
                    throw new \InvalidArgumentException('Malformed storage measurement item flag.');
                }

                return MeasurementItemFlag::from($flag);
            }, $flags)),
        );
    }
}
