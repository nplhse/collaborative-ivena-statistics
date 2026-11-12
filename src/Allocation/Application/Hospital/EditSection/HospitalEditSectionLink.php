<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\EditSection;

/**
 * @psalm-suppress PossiblyUnusedProperty Read by the hospital edit shell template.
 */
final readonly class HospitalEditSectionLink
{
    /**
     * @param array<string, int|string> $routeParameters
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $route,
        public array $routeParameters,
    ) {
    }
}
