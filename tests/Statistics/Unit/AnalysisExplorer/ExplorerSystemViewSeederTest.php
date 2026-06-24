<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerSystemViewSeeder;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExplorerSystemViewSeederTest extends KernelTestCase
{
    private const int EXPECTED_SYSTEM_VIEW_COUNT = 44;

    public function testDefinitionsCountMatchesLibraryStandardSet(): void
    {
        self::bootKernel();

        $seeder = self::getContainer()->get(ExplorerSystemViewSeeder::class);
        self::assertInstanceOf(ExplorerSystemViewSeeder::class, $seeder);

        self::assertCount(self::EXPECTED_SYSTEM_VIEW_COUNT, $seeder->definitions());
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
            $config = $mapper->buildViewConfig($filterState, $definition['preferences'], null);

            self::assertSame($definition['title'], $config->title, $definition['slug']);
            self::assertNotEmpty($config->metricKeys, $definition['slug']);

            if ('box_plot' === ($definition['preferences']['chartType'] ?? null)) {
                self::assertTrue($config->visualMetricKey->isDistributionProfile(), $definition['slug']);
                self::assertSame(ChartPresentationType::BoxPlot, $config->presentation->chartType, $definition['slug']);
            }
        }
    }

    public function testNewHeatmapAndHospitalLocationSeedsArePresent(): void
    {
        self::bootKernel();

        $seeder = self::getContainer()->get(ExplorerSystemViewSeeder::class);
        $slugs = array_map(
            static fn (array $definition): string => $definition['slug'],
            $seeder->definitions(),
        );

        self::assertContains('allocations-weekday-by-day-time-heatmap', $slugs);
        self::assertContains('allocations-weekday-by-shift-heatmap', $slugs);
        self::assertContains('allocations-by-hour', $slugs);
        self::assertContains('transport-time-bucket-distribution', $slugs);
        self::assertContains('overview-clinical-resources', $slugs);
        self::assertContains('overview-clinical-features', $slugs);
        self::assertContains('clinical-resources-by-gender', $slugs);
        self::assertContains('clinical-features-by-urgency', $slugs);
        self::assertContains('beds-distribution-by-location', $slugs);
        self::assertContains('cpr-distribution', $slugs);
    }
}
