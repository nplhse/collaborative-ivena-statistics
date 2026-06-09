<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Infrastructure\Geocoding;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final readonly class HospitalPopulationGeocodingLookupFactory
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function create(): HospitalPopulationGeocodingLookup
    {
        $path = $this->projectDir.'/config/hospital_population/geocoding.yaml';
        /** @var array{
         *     postal_codes?: array<string, array{latitude: float|int, longitude: float|int}>,
         *     cities?: array<string, array{latitude: float|int, longitude: float|int}>,
         *     dispatch_areas?: array<string, array{latitude: float|int, longitude: float|int}>
         * } $config
         */
        $config = Yaml::parseFile($path);

        return new HospitalPopulationGeocodingLookup(
            $config['postal_codes'] ?? [],
            $config['cities'] ?? [],
            $config['dispatch_areas'] ?? [],
        );
    }
}
