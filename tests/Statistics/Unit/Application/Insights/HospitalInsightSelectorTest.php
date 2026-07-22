<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Insights;

use App\Statistics\Application\Insights\HospitalInsightSelector;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistribution;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricFormat;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class HospitalInsightSelectorTest extends TestCase
{
    public function testPhysicianInsightUsesBaselineDeltaInPercentagePoints(): void
    {
        $selector = new HospitalInsightSelector($this->translator());

        $insights = $selector->select(
            null,
            null,
            [
                new BenchmarkMetric(
                    BenchmarkMetricKey::WithPhysician,
                    15.9,
                    13.4,
                    2.5,
                    18.7,
                    1.19,
                    BenchmarkMetricFormat::Percent,
                ),
            ],
            new BenchmarkDistribution(BenchmarkMetricKey::IndicationMix, []),
            null,
            'https://example.test/stats',
            'your last 12 months',
            'May 2026',
        );

        self::assertCount(1, $insights);
        self::assertSame('monthly_reminder.insight.physician.title', $insights[0]->title);
        self::assertStringContainsString('15.9', $insights[0]->body);
        self::assertStringContainsString('13.4', $insights[0]->body);
        self::assertStringContainsString('+2.5%', $insights[0]->body);
        self::assertSame('https://example.test/stats', $insights[0]->linkUrl);
    }

    public function testMetricInsideBaselineBandProducesNoInsight(): void
    {
        $selector = new HospitalInsightSelector($this->translator());

        $insights = $selector->select(
            null,
            null,
            [
                new BenchmarkMetric(
                    BenchmarkMetricKey::WithPhysician,
                    14.0,
                    13.4,
                    0.6,
                    4.5,
                    1.04,
                    BenchmarkMetricFormat::Percent,
                ),
            ],
            new BenchmarkDistribution(BenchmarkMetricKey::IndicationMix, []),
            null,
            'https://example.test/stats',
            'your last 12 months',
            'May 2026',
        );

        self::assertSame([], $insights);
    }

    public function testVolumeMomInsightIsSelectedWhenChangeIsSignificant(): void
    {
        $selector = new HospitalInsightSelector($this->translator());

        $insights = $selector->select(
            8.5,
            null,
            [],
            new BenchmarkDistribution(BenchmarkMetricKey::IndicationMix, []),
            null,
            'https://example.test/stats',
            'your last 12 months',
            'May 2026',
        );

        self::assertCount(1, $insights);
        self::assertSame('monthly_reminder.insight.volume_mom.title', $insights[0]->title);
    }

    public function testVolumeYoyInsightTakesPriorityOverMom(): void
    {
        $selector = new HospitalInsightSelector($this->translator());

        $insights = $selector->select(
            12.0,
            -6.0,
            [],
            new BenchmarkDistribution(BenchmarkMetricKey::IndicationMix, []),
            null,
            'https://example.test/stats',
            'your last 12 months',
            'May 2026',
        );

        self::assertCount(1, $insights);
        self::assertSame('monthly_reminder.insight.volume_yoy.title', $insights[0]->title);
    }

    public function testQualityInsightWhenRejectionRateImproves(): void
    {
        $selector = new HospitalInsightSelector($this->translator());

        $insights = $selector->select(
            null,
            null,
            [],
            new BenchmarkDistribution(BenchmarkMetricKey::IndicationMix, []),
            -2.4,
            'https://example.test/stats',
            'your last 12 months',
            'May 2026',
        );

        self::assertCount(1, $insights);
        self::assertSame('monthly_reminder.insight.quality.title', $insights[0]->title);
    }

    public function testResusInsightUsesBaselineComparison(): void
    {
        $selector = new HospitalInsightSelector($this->translator());

        $insights = $selector->select(
            null,
            null,
            [
                new BenchmarkMetric(
                    BenchmarkMetricKey::Resus,
                    4.0,
                    2.0,
                    2.0,
                    100.0,
                    2.0,
                    BenchmarkMetricFormat::Percent,
                ),
            ],
            new BenchmarkDistribution(BenchmarkMetricKey::IndicationMix, []),
            null,
            'https://example.test/stats',
            'your last 12 months',
            'May 2026',
        );

        self::assertCount(1, $insights);
        self::assertSame('monthly_reminder.insight.resus.title', $insights[0]->title);
    }

    public function testIndicationInsightUsesLargestDeviationBucket(): void
    {
        $selector = new HospitalInsightSelector($this->translator());

        $insights = $selector->select(
            null,
            null,
            [],
            new BenchmarkDistribution(BenchmarkMetricKey::IndicationMix, [
                new \App\Statistics\Benchmarking\Application\DTO\BenchmarkDistributionBucket(
                    'a',
                    'Chest pain',
                    10,
                    8,
                    12.0,
                    8.0,
                    1.5,
                ),
            ]),
            null,
            'https://example.test/stats',
            'your last 12 months',
            'May 2026',
        );

        self::assertCount(1, $insights);
        self::assertSame('monthly_reminder.insight.indication.title', $insights[0]->title);
        self::assertStringContainsString('Chest pain', $insights[0]->body);
    }

    public function testSelectPassesExplicitLocaleToTranslator(): void
    {
        $capturedLocale = null;
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (
                string $id,
                array $parameters = [],
                ?string $domain = null,
                ?string $locale = null,
            ) use (&$capturedLocale): string {
                $capturedLocale = $locale;

                return $id;
            },
        );

        new HospitalInsightSelector($translator)->select(
            10.0,
            null,
            [],
            new BenchmarkDistribution(BenchmarkMetricKey::IndicationMix, []),
            null,
            'https://example.test/stats',
            'your last 12 months',
            'May 2026',
            'de',
        );

        self::assertSame('de', $capturedLocale);
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = []): string {
                if ([] === $parameters) {
                    return $id;
                }

                $parts = [];
                foreach ($parameters as $key => $value) {
                    $parts[] = sprintf('%s=%s', $key, $value);
                }

                return $id.' ['.implode(', ', $parts).']';
            },
        );

        return $translator;
    }
}
