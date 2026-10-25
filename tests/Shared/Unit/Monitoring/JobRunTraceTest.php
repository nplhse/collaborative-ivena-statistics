<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Monitoring;

use App\Shared\Infrastructure\Monitoring\JobRun\JobRunTrace;
use PHPUnit\Framework\TestCase;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

final class JobRunTraceTest extends TestCase
{
    public function testPhaseSpansCarryTheSummedTimeOneAfterAnotherInsideTheirParent(): void
    {
        $transaction = $this->transaction();
        $trace = $this->trace($transaction);

        $trace->startSpan('import.rows');
        $trace->addPhaseSpans([
            'import.read' => ['seconds' => 1.5, 'data' => ['ms' => 1500]],
            'import.map' => ['seconds' => 0.5, 'data' => ['ms' => 500]],
            'import.persist' => ['seconds' => -1.0, 'data' => ['chunks' => 3]],
        ]);
        $trace->finishSpan(['rows' => 12]);
        $trace->finish(false);

        $spans = $this->spansByOp($transaction);
        $rows = $spans['import.rows'];
        $read = $spans['import.read'];
        $map = $spans['import.map'];
        $persist = $spans['import.persist'];

        self::assertSame($rows->getSpanId(), $read->getParentSpanId());
        self::assertSame($rows->getStartTimestamp(), $read->getStartTimestamp());
        self::assertEqualsWithDelta(1.5, (float) $read->getEndTimestamp() - $read->getStartTimestamp(), 1e-6);
        self::assertEqualsWithDelta((float) $read->getEndTimestamp(), $map->getStartTimestamp(), 1e-6);
        self::assertEqualsWithDelta(0.0, (float) $persist->getEndTimestamp() - $persist->getStartTimestamp(), 1e-6, 'Negative times are clamped.');
        self::assertSame(['ms' => 1500, 'summed' => true], $read->getData());
        self::assertSame(['chunks' => 3, 'summed' => true], $persist->getData());
        self::assertSame(['rows' => 12], $rows->getData());
        self::assertEquals(SpanStatus::ok(), $transaction->getStatus());
    }

    public function testFinishClosesOpenSpansOnceAndIgnoresLaterSpans(): void
    {
        $transaction = $this->transaction();
        $trace = $this->trace($transaction);

        $trace->startSpan('import.rows');
        $trace->finish(true, ['status' => 'failed']);
        $trace->finish(false);
        $trace->startSpan('import.finish');

        $spans = $this->spansByOp($transaction);
        self::assertNotNull($spans['import.rows']->getEndTimestamp());
        self::assertArrayNotHasKey('import.finish', $spans);
        self::assertEquals(SpanStatus::internalError(), $transaction->getStatus());
        self::assertSame(['status' => 'failed'], $transaction->getTags());
    }

    private function transaction(): Transaction
    {
        $transaction = new Transaction(TransactionContext::make()->setName('import_run')->setSampled(true));
        $transaction->initSpanRecorder();

        return $transaction;
    }

    private function trace(Transaction $transaction): JobRunTrace
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('startTransaction')->willReturn($transaction);

        return JobRunTrace::start($hub, 'import_run', 'import.allocations');
    }

    /**
     * @return array<string, Span>
     */
    private function spansByOp(Transaction $transaction): array
    {
        $spans = [];
        foreach ($transaction->getSpanRecorder()?->getSpans() ?? [] as $span) {
            $spans[(string) $span->getOp()] = $span;
        }

        return $spans;
    }
}
