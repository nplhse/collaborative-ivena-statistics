<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital;

final readonly class HospitalGeoScope
{
    public function __construct(
        public HospitalGeoScopeType $type,
        public int $id,
        public bool $participatingOnly,
    ) {
    }

    public static function hospital(int $id, bool $participatingOnly = false): self
    {
        return new self(HospitalGeoScopeType::Hospital, $id, $participatingOnly);
    }

    public static function dispatchArea(int $id, bool $participatingOnly = false): self
    {
        return new self(HospitalGeoScopeType::DispatchArea, $id, $participatingOnly);
    }

    public static function state(int $id, bool $participatingOnly = false): self
    {
        return new self(HospitalGeoScopeType::State, $id, $participatingOnly);
    }

    public function isHospital(): bool
    {
        return HospitalGeoScopeType::Hospital === $this->type;
    }
}
