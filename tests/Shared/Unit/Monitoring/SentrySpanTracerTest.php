<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Monitoring;

use App\Shared\Infrastructure\Monitoring\Sentry\SentrySpanTracer;
use PHPUnit\Framework\TestCase;
use Sentry\ClientBuilder;
use Sentry\State\Hub;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

final class SentrySpanTracerTest extends TestCase
{
    public function testRunsTheBlockWithoutATransaction(): void
    {
        $hub = new Hub(ClientBuilder::create()->getClient());

        self::assertSame(42, new SentrySpanTracer($hub)->trace('analysis.explorer.run', 'run', static fn (): int => 42));
        self::assertNull($hub->getSpan());
    }

    public function testWrapsTheBlockInAChildSpanThatIsCurrentWhileItRuns(): void
    {
        $hub = new Hub(ClientBuilder::create()->getClient());
        $transaction = new Transaction(TransactionContext::make()->setName('GET app_stats_analysis_explorer'), $hub);
        $transaction->initSpanRecorder();
        $hub->setSpan($transaction);

        $result = new SentrySpanTracer($hub)->trace(
            'analysis.explorer.run',
            'AnalysisExecution::execute',
            static function () use ($hub): array {
                // Stands in for a Doctrine query span started inside the block.
                $hub->getSpan()?->startChild(SpanContext::make()->setOp('db.sql.query'))->finish();

                return ['a', 'b', 'c'];
            },
            ['scope.type' => 'hospital', 'hospital_id' => 7],
            static fn (array $rows): array => ['analysis.rows' => \count($rows)],
        );

        self::assertSame(['a', 'b', 'c'], $result);
        self::assertSame($transaction, $hub->getSpan(), 'The parent is current again.');

        $spans = $transaction->getSpanRecorder()?->getSpans() ?? [];
        [, $run, $query] = $spans;
        self::assertSame('analysis.explorer.run', $run->getOp());
        self::assertSame(['scope.type' => 'hospital', 'hospital_id' => 7, 'analysis.rows' => 3], $run->getData());
        self::assertNotNull($run->getEndTimestamp());
        self::assertEquals($run->getSpanId(), $query->getParentSpanId());
    }

    public function testRestoresTheParentWhenTheBlockThrows(): void
    {
        $hub = new Hub(ClientBuilder::create()->getClient());
        $transaction = new Transaction(TransactionContext::make(), $hub);
        $hub->setSpan($transaction);

        $this->expectException(\RuntimeException::class);

        try {
            new SentrySpanTracer($hub)->trace('analysis.explorer.run', 'run', static fn (): int => throw new \RuntimeException('query failed'));
        } finally {
            self::assertSame($transaction, $hub->getSpan());
        }
    }
}
