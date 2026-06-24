<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\UI\Command;

use App\Statistics\CaseFlow\Infrastructure\GeoJson\HessenDispatchAreaGeoJsonBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'app:case-flow:build-geojson',
    description: 'Build merged Hessen dispatch-area GeoJSON for Case flow map overlays',
)]
final readonly class BuildCaseFlowGeoJsonCommand
{
    public function __construct(
        private HessenDispatchAreaGeoJsonBuilder $builder,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $sourcesPath = $this->projectDir.'/config/case_flow/dispatch_area_geo_sources.yaml';
        $rawPath = $this->projectDir.'/assets/geo/.source/hessen-kreise-raw.geojson';
        $outputPath = $this->projectDir.'/assets/geo/hessen-landkreise.geojson';

        /** @var array<string, mixed> $sourcesConfig */
        $sourcesConfig = Yaml::parseFile($sourcesPath);
        $collection = $this->builder->build($sourcesConfig, $rawPath);

        file_put_contents(
            $outputPath,
            json_encode($collection, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        $mergedCount = 0;
        foreach ($collection['features'] as $feature) {
            if (\count($feature['properties']['krs_names']) > 1) {
                ++$mergedCount;
            }
        }

        $io->success(sprintf(
            'Wrote %d dispatch-area features (%d merged) to %s',
            \count($collection['features']),
            $mergedCount,
            $outputPath,
        ));

        return Command::SUCCESS;
    }
}
