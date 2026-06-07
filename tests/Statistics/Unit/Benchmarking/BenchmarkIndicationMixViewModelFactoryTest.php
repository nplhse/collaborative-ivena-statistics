<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Benchmarking;

use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistribution;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistributionBucket;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use App\Statistics\Benchmarking\UI\Http\Controller\BenchmarkIndicationMixViewModelFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class BenchmarkIndicationMixViewModelFactoryTest extends KernelTestCase
{
    public function testSplitsBucketsAndBuildsInsightsUrls(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(BenchmarkIndicationMixViewModelFactory::class);

        $distribution = new BenchmarkDistribution(BenchmarkMetricKey::IndicationMix, [
            new BenchmarkDistributionBucket('42', 'STEMI', 100, 200, 20.0, 10.0, 2.0),
            new BenchmarkDistributionBucket('7', 'Stroke', 50, 400, 5.0, 20.0, 0.25),
            new BenchmarkDistributionBucket('invalid', 'Broken', 50, 400, 5.0, 20.0, 0.25),
        ]);

        $viewModel = $factory->create(
            new Request(query: ['scope' => 'public', 'period' => 'all']),
            $distribution,
        );

        self::assertCount(1, $viewModel->overRepresented);
        self::assertSame('42', $viewModel->overRepresented[0]->bucket->key);
        self::assertStringContainsString('/statistics/indication/42', (string) $viewModel->overRepresented[0]->insightsUrl);
        self::assertStringContainsString('scope=public', (string) $viewModel->overRepresented[0]->insightsUrl);

        self::assertCount(2, $viewModel->underRepresented);
        self::assertNull($viewModel->underRepresented[1]->insightsUrl);
        self::assertFalse($viewModel->canExpandOver());
        self::assertFalse($viewModel->canExpandUnder());
    }

    public function testReportsExpandFlagsWhenMoreThanInitialCount(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(BenchmarkIndicationMixViewModelFactory::class);

        $overRows = [];
        for ($index = 1; $index <= 6; ++$index) {
            $overRows[] = new BenchmarkDistributionBucket((string) $index, 'Over '.$index, 100, 200, 20.0, 10.0, 2.0);
        }

        $underRows = [];
        for ($index = 1; $index <= 3; ++$index) {
            $underRows[] = new BenchmarkDistributionBucket('u'.$index, 'Under '.$index, 50, 400, 5.0, 20.0, 0.25);
        }

        $viewModel = $factory->create(
            new Request(),
            new BenchmarkDistribution(BenchmarkMetricKey::IndicationMix, [...$overRows, ...$underRows]),
        );

        self::assertTrue($viewModel->canExpandOver());
        self::assertFalse($viewModel->canExpandUnder());
        self::assertCount(6, $viewModel->overRepresented);
    }

    public function testReportsEmptyViewModel(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(BenchmarkIndicationMixViewModelFactory::class);

        $viewModel = $factory->create(
            new Request(),
            new BenchmarkDistribution(BenchmarkMetricKey::IndicationMix, []),
        );

        self::assertTrue($viewModel->isEmpty());
    }
}
