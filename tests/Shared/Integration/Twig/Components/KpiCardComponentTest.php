<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class KpiCardComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    protected function tearDown(): void
    {
        if (self::$booted) {
            $this->localeSwitcher()->reset();
        }

        parent::tearDown();
    }

    public function testPlainCountIsFormattedForTheGermanLocale(): void
    {
        $crawler = $this->render(['label' => 'Zuweisungen', 'value' => 1240], 'de');

        self::assertSame('Zuweisungen', $this->text($crawler, '.subheader'));
        self::assertSame('1.240', $this->text($crawler, '.h1'));
        self::assertCount(0, $crawler->filter('[data-kpi-trend]'));
        self::assertCount(0, $crawler->filter('.avatar'));
    }

    public function testPlainCountIsFormattedForTheEnglishLocale(): void
    {
        $crawler = $this->render(['label' => 'Allocations', 'value' => 1240], 'en');

        self::assertSame('1,240', $this->text($crawler, '.h1'));
    }

    public function testMissingValueShowsPlaceholderAndHiddenText(): void
    {
        $crawler = $this->render(['label' => 'Allocations', 'value' => null], 'en');

        self::assertSame('—', $this->text($crawler, '.h1 [aria-hidden="true"]'));
        self::assertSame('not available', $this->text($crawler, '.h1 .visually-hidden'));
    }

    public function testSparklinePassesItsValuesToTablerAndNamesThemForScreenReaders(): void
    {
        $crawler = $this->render([
            'label' => 'Allocations',
            'value' => 1240,
            'sparkline' => [['label' => 'Feb 2024', 'value' => 980], ['label' => 'Mar 2024', 'value' => 0], ['label' => 'Apr 2024', 'value' => 1240]],
        ], 'en');

        $sparkline = $crawler->filter('[data-testid="kpi-sparkline"]');
        self::assertCount(1, $sparkline);
        self::assertSame('980,0,1240', $sparkline->attr('data-bs-values'));
        self::assertSame('sparkline', $sparkline->attr('data-bs-toggle'));
        self::assertSame('tabler-sparkline', $sparkline->attr('data-controller'));
        self::assertSame('Trend: Feb 2024: 980, Mar 2024: 0, Apr 2024: 1,240', $this->text($crawler, '[data-testid="kpi-sparkline"] + .visually-hidden'));
        self::assertSame(
            ['Feb 2024: 980', 'Mar 2024: 0', 'Apr 2024: 1,240'],
            $crawler->filter('[data-testid="kpi-sparkline"] .kpi-sparkline-point')->each(static fn (Crawler $point): string => (string) $point->attr('title')),
        );
    }

    public function testFewerThanTwoPointsShowNoSparkline(): void
    {
        $crawler = $this->render(['label' => 'Allocations', 'value' => 3, 'sparkline' => [['label' => 'Apr 2024', 'value' => 3]]], 'en');

        self::assertCount(0, $crawler->filter('[data-testid="kpi-sparkline"]'));
    }

    public function testZeroValueIsShownAsZero(): void
    {
        $crawler = $this->render(['label' => 'Allocations', 'value' => 0], 'en');

        self::assertSame('0', $this->text($crawler, '.h1'));
    }

    public function testStringValueIsPrintedAsGiven(): void
    {
        $crawler = $this->render(['label' => 'Duration', 'value' => '3 h 20 min'], 'de');

        self::assertSame('3 h 20 min', $this->text($crawler, '.h1'));
    }

    public function testIncreaseWithComparisonLabelIsNeutralByDefault(): void
    {
        $crawler = $this->render([
            'label' => 'Users',
            'value' => 42,
            'delta' => 12,
            'comparisonLabel' => 'last 30 days',
        ], 'en');

        $trend = $crawler->filter('[data-kpi-trend="up"]');
        self::assertCount(1, $trend);
        self::assertSame('+12', $this->text($trend, '.lh-1'));
        self::assertStringContainsString('text-secondary', (string) $trend->filter('.lh-1')->attr('class'));
        self::assertStringContainsString('text-secondary', (string) $trend->filter('svg')->attr('class'));
        self::assertStringContainsString('last 30 days', $trend->text());
        self::assertSame('Increase', $this->text($trend, '.visually-hidden'));
        self::assertCount(1, $trend->filter('svg'));
    }

    public function testDecreaseAndZeroDelta(): void
    {
        $decrease = $this->render(['label' => 'Users', 'value' => 42, 'delta' => -3], 'en');
        self::assertSame('-3', $this->text($decrease, '[data-kpi-trend="down"] .lh-1'));
        self::assertSame('Decrease', $this->text($decrease, '[data-kpi-trend="down"] .visually-hidden'));

        $zero = $this->render(['label' => 'Users', 'value' => 42, 'delta' => 0], 'en');
        self::assertSame('0', $this->text($zero, '[data-kpi-trend="flat"] .lh-1'));
        self::assertSame('No change', $this->text($zero, '[data-kpi-trend="flat"] .visually-hidden'));
    }

    public function testColouredAndInvertedTrend(): void
    {
        $favourable = $this->render(['label' => 'Imports', 'value' => 5, 'delta' => 2, 'colorizeTrend' => true], 'en');
        // Only the icon carries the assessment colour; the delta text stays grey.
        self::assertStringContainsString('text-green', (string) $favourable->filter('[data-kpi-trend] svg')->attr('class'));
        self::assertStringContainsString('text-secondary', (string) $favourable->filter('[data-kpi-trend] .lh-1')->attr('class'));
        self::assertStringNotContainsString('text-green', (string) $favourable->filter('[data-kpi-trend] .lh-1')->attr('class'));

        $unfavourable = $this->render([
            'label' => 'Closures',
            'value' => 5,
            'delta' => 2,
            'colorizeTrend' => true,
            'invertTrend' => true,
        ], 'en');
        self::assertStringContainsString('text-red', (string) $unfavourable->filter('[data-kpi-trend="up"] svg')->attr('class'));
        self::assertStringNotContainsString('text-red', (string) $unfavourable->filter('[data-kpi-trend="up"] .lh-1')->attr('class'));
        self::assertSame('Increase', $this->text($unfavourable, '[data-kpi-trend] .visually-hidden'));
    }

    public function testFractionDigitsApplyToValueAndDelta(): void
    {
        $de = $this->render(['label' => 'Rate', 'value' => 12.34, 'delta' => 0.5, 'fractionDigits' => 1], 'de');
        self::assertSame('12,3', $this->text($de, '.h1'));
        self::assertSame('+0,5', $this->text($de, '[data-kpi-trend] .lh-1'));

        $en = $this->render(['label' => 'Rate', 'value' => 12.34, 'delta' => 0.5, 'fractionDigits' => 1], 'en');
        self::assertSame('12.3', $this->text($en, '.h1'));
        self::assertSame('+0.5', $this->text($en, '[data-kpi-trend] .lh-1'));
    }

    public function testTrailingZeroFractionDigitsAreDropped(): void
    {
        $de = $this->render(['label' => 'Rate', 'value' => 10.0, 'unit' => '%', 'delta' => 2.0, 'fractionDigits' => 1], 'de');
        self::assertSame('10 %', $this->text($de, '.h1'));
        self::assertSame('+2 %', $this->text($de, '[data-kpi-trend] .lh-1'));

        // 10.04 rounds to 10.0 and is shown as 10; 10.05 rounds to 10.1 (half up for the delta, ICU for the value).
        $en = $this->render(['label' => 'Rate', 'value' => 10.04, 'delta' => 1.25, 'fractionDigits' => 1], 'en');
        self::assertSame('10', $this->text($en, '.h1'));
        self::assertSame('+1.3', $this->text($en, '[data-kpi-trend] .lh-1'));
    }

    public function testDeltaThatRoundsToZeroIsShownAsUnchanged(): void
    {
        $positive = $this->render(['label' => 'Rate', 'value' => 1, 'delta' => 0.04, 'fractionDigits' => 1], 'de');
        self::assertSame('0', $this->text($positive, '[data-kpi-trend="flat"] .lh-1'));
        self::assertSame('Keine Veränderung', $this->text($positive, '[data-kpi-trend] .visually-hidden'));

        $negative = $this->render(['label' => 'Rate', 'value' => 1, 'delta' => -0.04, 'fractionDigits' => 1], 'de');
        self::assertSame('0', $this->text($negative, '[data-kpi-trend="flat"] .lh-1'));
    }

    public function testUnitFollowsValueAndDeltaAndDescriptionRenders(): void
    {
        $crawler = $this->render([
            'label' => 'Mean transport time',
            'value' => 12,
            'unit' => 'min',
            'delta' => 3,
            'description' => 'Median 10 min',
        ], 'en');

        self::assertSame('12 min', $this->text($crawler, '.h1'));
        self::assertSame('+3 min', $this->text($crawler, '[data-kpi-trend] .lh-1'));
        self::assertSame('Median 10 min', $this->text($crawler, '.h1 + .small'));
        // The description sits between the value and the trend line.
        self::assertCount(1, $crawler->filter('.h1 + .small + [data-kpi-trend]'));
    }

    public function testDeltaUnitReplacesTheValueUnitOnTheTrendLineOnly(): void
    {
        $crawler = $this->render([
            'label' => 'Physician share',
            'value' => 25.5,
            'unit' => '%',
            'delta' => 5.5,
            'deltaUnit' => 'pp',
            'fractionDigits' => 1,
        ], 'en');

        self::assertSame('25.5 %', $this->text($crawler, '.h1'));
        self::assertSame('+5.5 pp', $this->text($crawler, '[data-kpi-trend] .lh-1'));
    }

    public function testDeltaUnitWorksWithoutAValueUnit(): void
    {
        $crawler = $this->render([
            'label' => 'Closed department',
            'value' => '12',
            'delta' => -12.5,
            'deltaUnit' => '%',
            'fractionDigits' => 1,
        ], 'de');

        self::assertSame('12', $this->text($crawler, '.h1'));
        self::assertSame('-12,5 %', $this->text($crawler, '[data-kpi-trend="down"] .lh-1'));
    }

    public function testIconRendersInAvatar(): void
    {
        $crawler = $this->render(['label' => 'Users', 'value' => 1, 'icon' => 'tabler:users'], 'en');

        self::assertCount(1, $crawler->filter('.avatar svg'));
    }

    public function testMissingLabelFailsToMount(): void
    {
        $this->expectException(\Throwable::class);

        $this->renderTwigComponent('KpiCard', ['value' => 1]);
    }

    public function testLinkedCardForwardsHrefClassAndAttributesThroughCard(): void
    {
        $crawler = $this->render([
            'label' => 'Users',
            'value' => 1,
            'href' => '/explore/user',
            'class' => 'h-100',
            'data-testid' => 'dashboard-metric-users',
            'data-foo' => 'bar',
        ], 'en');

        $root = $crawler->filter('body > *');
        self::assertCount(1, $root);
        self::assertSame('a', $root->nodeName());
        self::assertSame('card card-link h-100', $root->attr('class'));
        self::assertSame('/explore/user', $root->attr('href'));
        self::assertSame('dashboard-metric-users', $root->attr('data-testid'));
        self::assertSame('bar', $root->attr('data-foo'));
    }

    public function testCardWithoutHrefIsADiv(): void
    {
        $crawler = $this->render(['label' => 'Users', 'value' => 1, 'data-testid' => 'metric'], 'en');

        $root = $crawler->filter('body > *');
        self::assertSame('div', $root->nodeName());
        self::assertSame('card', $root->attr('class'));
        self::assertNull($root->attr('href'));
        self::assertSame('metric', $root->attr('data-testid'));
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props, string $locale): Crawler
    {
        self::bootKernel();
        $this->localeSwitcher()->setLocale($locale);

        return new Crawler((string) $this->renderTwigComponent('KpiCard', $props));
    }

    private function text(Crawler $crawler, string $selector): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $crawler->filter($selector)->text()));
    }

    private function localeSwitcher(): LocaleSwitcher
    {
        $switcher = self::getContainer()->get('translation.locale_switcher');
        self::assertInstanceOf(LocaleSwitcher::class, $switcher);

        return $switcher;
    }
}
