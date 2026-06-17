<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Unit\Application;

use App\Engagement\Application\MonthlyReminderInsightSelector;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistribution;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricFormat;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MonthlyReminderInsightSelectorTest extends TestCase
{
    public function testPhysicianInsightUsesBaselineDeltaInPercentagePoints(): void
    {
        $selector = new MonthlyReminderInsightSelector($this->translator());

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
        $selector = new MonthlyReminderInsightSelector($this->translator());

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

    private function translator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
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
