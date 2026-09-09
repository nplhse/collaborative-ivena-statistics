<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital;

use App\Allocation\Domain\Entity\Hospital;

final readonly class HospitalGeoScopeResolution
{
    /**
     * @param list<Hospital> $hospitals
     */
    public function __construct(
        public bool $success,
        public ?string $error = null,
        public string $label = '',
        public array $hospitals = [],
    ) {
    }

    /**
     * @param list<Hospital> $hospitals
     */
    public static function ok(string $label, array $hospitals): self
    {
        return new self(success: true, label: $label, hospitals: $hospitals);
    }

    public static function error(string $error): self
    {
        return new self(success: false, error: $error);
    }
}
