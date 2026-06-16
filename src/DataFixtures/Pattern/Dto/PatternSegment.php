<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Dto;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;

final readonly class PatternSegment
{
    public function __construct(
        public HospitalTier $hospitalTier,
        public HospitalLocation $hospitalLocation,
    ) {
    }

    /**
     * @param array{hospital_tier: string, hospital_location: string} $data
     */
    public static function fromArray(array $data): self
    {
        $tier = HospitalTier::tryFrom($data['hospital_tier']);
        $location = HospitalLocation::tryFrom($data['hospital_location']);
        if (!$tier instanceof HospitalTier || !$location instanceof HospitalLocation) {
            throw new \InvalidArgumentException('Invalid pattern segment definition.');
        }

        return new self($tier, $location);
    }

    /**
     * @return array{hospital_tier: string, hospital_location: string}
     */
    public function toArray(): array
    {
        return [
            'hospital_tier' => $this->hospitalTier->value,
            'hospital_location' => $this->hospitalLocation->value,
        ];
    }
}
