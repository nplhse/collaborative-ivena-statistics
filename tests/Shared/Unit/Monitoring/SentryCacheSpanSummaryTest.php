<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Monitoring;

use App\Shared\Infrastructure\Monitoring\Sentry\SentryCacheSpanSummary;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanId;

final class SentryCacheSpanSummaryTest extends TestCase
{
    public function testReplacesCacheSpansByCountsAndTheOutermostDuration(): void
    {
        $root = SpanId::generate();
        // 10 ms hit.
        $hit = $this->span('cache.get', 100.000, 100.010, $root, ['cache.hit' => true]);
        // 50 ms miss whose value computation runs a query and a nested 5 ms cache miss.
        $miss = $this->span('cache.get', 100.100, 100.150, $root, ['cache.hit' => false]);
        $nested = $this->span('cache.get', 100.110, 100.115, $miss->getSpanId(), ['cache.hit' => false]);
        $query = $this->span('db.sql.query', 100.120, 100.140, $nested->getSpanId());
        // 2 ms write.
        $put = $this->span('cache.put', 100.150, 100.152, $root);
        $other = $this->span('db.sql.query', 100.200, 100.210, $root);

        $event = Event::createTransaction();
        $event->setContext('trace', ['op' => 'http.server', 'data' => ['route' => 'app_default']]);
        $event->setSpans([$hit, $miss, $nested, $query, $put, $other]);

        new SentryCacheSpanSummary()->summarize($event);

        self::assertSame([$query, $other], $event->getSpans());
        self::assertEquals($root, $query->getParentSpanId(), 'Moved up from the dropped cache spans.');
        self::assertSame([
            'route' => 'app_default',
            'cache.calls' => 4,
            'cache.hits' => 1,
            'cache.misses' => 2,
            'cache.writes' => 1,
            'cache.duration_ms' => 62.0,
        ], $event->getContexts()['trace']['data']);
    }

    public function testLeavesTransactionsWithoutCacheSpansAlone(): void
    {
        $query = $this->span('db.sql.query', 1.0, 1.1, SpanId::generate());
        $event = Event::createTransaction();
        $event->setContext('trace', ['data' => ['route' => 'app_default']]);
        $event->setSpans([$query]);

        new SentryCacheSpanSummary()->summarize($event);

        self::assertSame([$query], $event->getSpans());
        self::assertSame(['route' => 'app_default'], $event->getContexts()['trace']['data']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function span(string $op, float $start, float $end, SpanId $parent, array $data = []): Span
    {
        $span = new Span(SpanContext::make()->setOp($op)->setStartTimestamp($start)->setParentSpanId($parent)->setData($data));
        $span->finish($end);

        return $span;
    }
}
