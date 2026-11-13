<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\MyHospitals\Action;

/**
 * A quick action of another context in the footer of a My Hospitals card, between the statistics and
 * the import buttons.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the My Hospitals card template.
 */
final readonly class MyHospitalAction
{
    /**
     * @param array<string, int|string> $routeParameters
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $translationDomain,
        public string $icon,
        public string $route,
        public array $routeParameters = [],
        public int $position = 0,
    ) {
    }
}
