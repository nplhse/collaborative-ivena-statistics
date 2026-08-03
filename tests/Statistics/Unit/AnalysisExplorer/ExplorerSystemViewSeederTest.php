<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerSystemViewSeeder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExplorerSystemViewSeederTest extends KernelTestCase
{
    private const int EXPECTED_SYSTEM_VIEW_COUNT = 1;

    public function testDefinitionsContainOnlyActiveTimeSeriesSlice(): void
    {
        self::bootKernel();

        $seeder = self::getContainer()->get(ExplorerSystemViewSeeder::class);
        self::assertInstanceOf(ExplorerSystemViewSeeder::class, $seeder);

        $definitions = $seeder->definitions();
        self::assertCount(self::EXPECTED_SYSTEM_VIEW_COUNT, $definitions);
        self::assertSame('allocations-over-time', $definitions[0]['slug']);
        self::assertSame('time_series', $definitions[0]['analysisFamily'] ?? null);
    }

    public function testDefinitionsHaveUniqueSlugs(): void
    {
        self::bootKernel();

        $seeder = self::getContainer()->get(ExplorerSystemViewSeeder::class);
        $slugs = array_map(
            static fn (array $definition): string => $definition['slug'],
            $seeder->definitions(),
        );

        self::assertCount(\count($slugs), array_unique($slugs));
    }

    public function testAllDefinitionsBuildValidViewConfig(): void
    {
        self::bootKernel();

        $seeder = self::getContainer()->get(ExplorerSystemViewSeeder::class);
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        self::assertInstanceOf(ExplorerConfigMapper::class, $mapper);

        $filterState = [
            'scope' => ['group' => 'public', 'detail' => null],
            'period' => ['type' => 'all', 'year' => null, 'quarter' => null, 'month' => null],
        ];

        foreach ($seeder->definitions() as $definition) {
            $preferences = $definition['preferences'];
            $preferences['title'] = ExplorerSystemViewSeeder::titleKey($definition['slug']);
            $config = $mapper->buildViewConfig($filterState, $preferences, null);

            self::assertSame(ExplorerSystemViewSeeder::titleKey($definition['slug']), $config->title, $definition['slug']);
            self::assertNotEmpty($config->metricKeys, $definition['slug']);
        }
    }
}
