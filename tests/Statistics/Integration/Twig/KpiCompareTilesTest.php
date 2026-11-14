<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Twig;

use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricFormat;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Twig\Environment;

final class KpiCompareTilesTest extends KernelTestCase
{
    public function testShareDeltaIsLabelledAsPercentagePoints(): void
    {
        // 25,0 % vs. 20,0 %: 5,0 percentage points apart, a relative change of +25 %.
        $html = $this->render('en', [$this->physicianShare()]);

        self::assertStringContainsString('25,0%', $html);
        self::assertStringContainsString('20,0%', $html);
        self::assertMatchesRegularExpression('/\+5,0\s+<span title="percentage points \(difference between two shares\)">/', $html);
        self::assertStringContainsString('<span aria-hidden="true">pp</span>', $html);
        self::assertStringContainsString('<span class="visually-hidden">percentage points (difference between two shares)</span>', $html);
        self::assertStringNotContainsString('+5,0%', $html);
    }

    public function testShareDeltaUsesGermanUnit(): void
    {
        $html = $this->render('de', [$this->physicianShare()]);

        self::assertStringContainsString('<span aria-hidden="true">%-Punkte</span>', $html);
        self::assertStringContainsString('title="Prozentpunkte (Differenz zweier Anteile)"', $html);
    }

    public function testCountDeltaHasNoPercentagePointUnit(): void
    {
        $html = $this->render('en', [
            new BenchmarkMetric(BenchmarkMetricKey::Total, 120.0, 100.0, 20.0, 20.0, 1.2, BenchmarkMetricFormat::Count),
        ]);

        self::assertStringContainsString('+20', $html);
        self::assertStringNotContainsString('percentage points', $html);
    }

    private function physicianShare(): BenchmarkMetric
    {
        return new BenchmarkMetric(BenchmarkMetricKey::WithPhysician, 25.0, 20.0, 5.0, 25.0, 1.25, BenchmarkMetricFormat::Percent);
    }

    /**
     * @param list<BenchmarkMetric> $metrics
     */
    private function render(string $locale, array $metrics): string
    {
        self::bootKernel();
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(LocaleAwareInterface::class, $translator);
        $translator->setLocale($locale);

        $twig = self::getContainer()->get(Environment::class);

        return $twig->render('@Statistics/_kpi_compare_tiles.html.twig', [
            'metrics' => $metrics,
            'metricLabelKeys' => [],
            'suppressRatios' => false,
            'sideAShortKey' => 'stats.insights.compare.side_a_short',
            'sideBShortKey' => 'stats.insights.compare.side_b_short',
            'testIdPrefix' => 'test',
        ]);
    }
}
