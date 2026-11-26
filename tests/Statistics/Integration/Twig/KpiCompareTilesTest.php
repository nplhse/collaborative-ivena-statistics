<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Twig;

use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricFormat;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
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

    public function testUndefinedRatioIsShownAsNotAvailable(): void
    {
        // 5 vs. 0 cases: the ratio 5 / 0 is undefined; the builder stores 0.0.
        $html = $this->render('en', [
            new BenchmarkMetric(BenchmarkMetricKey::Total, 5.0, 0.0, 5.0, 0.0, 0.0, BenchmarkMetricFormat::Count),
        ]);

        self::assertStringContainsString('data-testid="test-total-ratio-unavailable"', $html);
        self::assertStringContainsString('<span class="visually-hidden">Ratio not computable: the comparison value is 0</span>', $html);
        self::assertStringNotContainsString('0,0×', $html);
        self::assertStringContainsString('+5', $html);
    }

    public function testUndefinedRatioUsesGermanText(): void
    {
        $html = $this->render('de', [
            new BenchmarkMetric(BenchmarkMetricKey::Total, 5.0, 0.0, 5.0, 0.0, 0.0, BenchmarkMetricFormat::Count),
        ]);

        self::assertStringContainsString('title="Verhältnis nicht berechenbar: Vergleichswert ist 0"', $html);
    }

    public function testDefinedRatioIsShown(): void
    {
        // 120 vs. 100 cases: ratio 1,2.
        $html = $this->render('en', [
            new BenchmarkMetric(BenchmarkMetricKey::Total, 120.0, 100.0, 20.0, 20.0, 1.2, BenchmarkMetricFormat::Count),
        ]);

        self::assertStringContainsString('1,2×', $html);
        self::assertStringNotContainsString('ratio-unavailable', $html);
    }

    /**
     * @return iterable<string, array{BenchmarkMetric, string, string}>
     */
    public static function assessedMetrics(): iterable
    {
        // Mean transport time 30 vs. 25 min: +5,0 min, ratio 1,2 — longer is unfavourable.
        yield 'longer transport' => [new BenchmarkMetric(BenchmarkMetricKey::MeanTransport, 30.0, 25.0, 5.0, 20.0, 1.2, BenchmarkMetricFormat::Minutes), 'text-danger', 'bg-red-lt'];
        // 20 vs. 25 min: -5,0 min, ratio 0,8 — shorter is favourable.
        yield 'shorter transport' => [new BenchmarkMetric(BenchmarkMetricKey::MeanTransport, 20.0, 25.0, -5.0, -20.0, 0.8, BenchmarkMetricFormat::Minutes), 'text-success', 'bg-green-lt'];
        // Physician share 25,0 % vs. 20,0 %: +5,0 pp, ratio 1,25 — more is favourable.
        yield 'more physician' => [new BenchmarkMetric(BenchmarkMetricKey::WithPhysician, 25.0, 20.0, 5.0, 25.0, 1.25, BenchmarkMetricFormat::Percent), 'text-success', 'bg-green-lt'];
        // Cath lab share 4,0 % vs. 6,0 %: -2,0 pp, ratio 0,67 — less is unfavourable.
        yield 'less cath lab' => [new BenchmarkMetric(BenchmarkMetricKey::Cathlab, 4.0, 6.0, -2.0, -33.3, 0.67, BenchmarkMetricFormat::Percent), 'text-danger', 'bg-red-lt'];
        // Resuscitation bay share 6,0 % vs. 4,0 %: +2,0 pp, ratio 1,5 — more is favourable.
        yield 'more resus' => [new BenchmarkMetric(BenchmarkMetricKey::Resus, 6.0, 4.0, 2.0, 50.0, 1.5, BenchmarkMetricFormat::Percent), 'text-success', 'bg-green-lt'];
        // Median age 70 vs. 65 years: case mix, neutral.
        yield 'older patients' => [new BenchmarkMetric(BenchmarkMetricKey::MedianAge, 70.0, 65.0, 5.0, 7.7, 1.08, BenchmarkMetricFormat::Years), 'text-secondary', 'bg-secondary-lt'];
        // 120 vs. 100 allocations: volume, neutral.
        yield 'more allocations' => [new BenchmarkMetric(BenchmarkMetricKey::Total, 120.0, 100.0, 20.0, 20.0, 1.2, BenchmarkMetricFormat::Count), 'text-secondary', 'bg-secondary-lt'];
        // Weekend share 30,0 % vs. 28,0 %: neutral.
        yield 'more weekend' => [new BenchmarkMetric(BenchmarkMetricKey::Weekend, 30.0, 28.0, 2.0, 7.1, 1.07, BenchmarkMetricFormat::Percent), 'text-secondary', 'bg-secondary-lt'];
        // Physician share 20,04 % vs. 20,0 %: shown as +0,0 pp, so no change.
        yield 'physician rounds to zero' => [new BenchmarkMetric(BenchmarkMetricKey::WithPhysician, 20.04, 20.0, 0.04, 0.2, 1.002, BenchmarkMetricFormat::Percent), 'text-secondary', 'bg-secondary-lt'];
        // Mean transport 25 vs. 25 min: no change.
        yield 'same transport' => [new BenchmarkMetric(BenchmarkMetricKey::MeanTransport, 25.0, 25.0, 0.0, 0.0, 1.0, BenchmarkMetricFormat::Minutes), 'text-secondary', 'bg-secondary-lt'];
    }

    #[DataProvider('assessedMetrics')]
    public function testColourFollowsTheMeaningOfTheMetric(BenchmarkMetric $metric, string $textClass, string $badgeClass): void
    {
        $tile = new Crawler($this->render('en', [$metric]))->filter('[data-testid="test-'.$metric->key->value.'"]');

        $delta = (string) $tile->filter('.h2')->attr('class');
        self::assertStringContainsString($textClass, $delta);
        foreach (['text-success', 'text-danger', 'text-secondary'] as $other) {
            if ($other !== $textClass) {
                self::assertStringNotContainsString($other, $delta);
            }
        }
        self::assertStringContainsString($badgeClass, (string) $tile->filter('.h2 + .badge')->attr('class'));
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
