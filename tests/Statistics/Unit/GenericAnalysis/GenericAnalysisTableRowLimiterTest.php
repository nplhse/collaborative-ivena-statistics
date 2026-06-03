<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Application\GenericAnalysisTableRowLimiter;
use App\Statistics\GenericAnalysis\Application\MetricValueFormatter;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GenericAnalysisTableRowLimiterTest extends TestCase
{
    private GenericAnalysisTableRowLimiter $limiter;

    protected function setUp(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Other');

        $this->limiter = new GenericAnalysisTableRowLimiter(
            new MetricValueFormatter(new MetricRegistry()),
            $translator,
        );
    }

    public function testReturnsAllRowsWhenCapIsNull(): void
    {
        $rows = [
            GenericAnalysisTestFixtures::enrichedRow('1', 'A', 1),
            GenericAnalysisTestFixtures::enrichedRow('2', 'B', 2),
        ];

        [$limited, $hasOther] = $this->limiter->limit($rows, null, 3, ['count']);

        self::assertSame($rows, $limited);
        self::assertFalse($hasOther);
    }

    public function testAggregatesRemainderIntoOtherRow(): void
    {
        $rows = [];
        for ($i = 1; $i <= 8; ++$i) {
            $rows[] = GenericAnalysisTestFixtures::enrichedRow((string) $i, 'Bucket '.$i, $i);
        }

        [$limited, $hasOther] = $this->limiter->limit($rows, 5, 36, ['count', 'percent_of_total']);

        self::assertTrue($hasOther);
        self::assertCount(6, $limited);
        self::assertSame('Other', $limited[5]->bucketLabel);
        self::assertSame(6, $limited[5]->countValue());
        self::assertEqualsWithDelta(16.67, $limited[5]->percentOfTotal(), 0.1);
    }
}
