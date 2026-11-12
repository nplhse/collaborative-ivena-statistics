<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\MyHospitals;

/**
 * The hospitals of My Hospitals the page shows: all of them (default) or a single one. A requested public ID outside
 * the scope is ignored and the page shows all hospitals.
 */
final readonly class MyHospitalsSelection
{
    private function __construct(
        public MyHospitalsScope $available,
        public ?MyHospitalsEntry $single,
    ) {
    }

    public static function fromPublicId(MyHospitalsScope $available, ?string $publicId): self
    {
        if (null !== $publicId && '' !== $publicId) {
            foreach ($available->entries as $entry) {
                if (0 === strcasecmp($entry->hospital->getPublicIdString(), $publicId)) {
                    return new self($available, $entry);
                }
            }
        }

        return new self($available, null);
    }

    /**
     * The hospitals to show: the selected one, or all of them.
     */
    public function selected(): MyHospitalsScope
    {
        return $this->single instanceof MyHospitalsEntry ? new MyHospitalsScope([$this->single]) : $this->available;
    }

    public function isAll(): bool
    {
        return !$this->single instanceof MyHospitalsEntry;
    }

    /**
     * A choice is only offered when there is more than one hospital.
     */
    public function canChoose(): bool
    {
        return $this->available->count() > 1;
    }

    public function isSelected(MyHospitalsEntry $entry): bool
    {
        return $this->single === $entry;
    }
}
