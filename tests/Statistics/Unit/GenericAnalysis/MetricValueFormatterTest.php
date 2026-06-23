<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Application\MetricValueFormatter;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use PHPUnit\Framework\TestCase;

final class MetricValueFormatterTest extends TestCase
{
    private MetricValueFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new MetricValueFormatter(new MetricRegistry());
    }

    public function testFormatsPercentMinutesAndDecimal(): void
    {
        $registry = new MetricRegistry();

        self::assertSame('8,4 %', $this->formatter->format($registry->get('percent_of_total'), 8.4));
        self::assertSame('8,4 %', $this->formatter->format($registry->get('resus_rate'), 8.4));
        self::assertSame('18 min', $this->formatter->format($registry->get('median_transport_time'), 18));
        self::assertSame('123', $this->formatter->format($registry->get('count'), 123));
        self::assertSame('12 %', $this->formatter->format($registry->get('percent_of_total'), 12.0));
    }
}
