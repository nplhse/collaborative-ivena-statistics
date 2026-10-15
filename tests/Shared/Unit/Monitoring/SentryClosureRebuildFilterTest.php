<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Monitoring;

use App\Shared\Infrastructure\Monitoring\Sentry\SentryClosureRebuildFilter;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;

final class SentryClosureRebuildFilterTest extends TestCase
{
    public function testClosureLogsKeepOnlyIdsCountsAndSdkAttributes(): void
    {
        $filter = new SentryClosureRebuildFilter();

        self::assertTrue($filter->isClosureLog('closure_rebuild.summary'));
        self::assertTrue($filter->isClosureLog('closure_volume.late_requests'));
        self::assertFalse($filter->isClosureLog('import.summary'));
        self::assertSame(
            [
                'sentry.environment' => 'beta',
                'run_id' => '0199e5a0-1c2b-7000-8000-000000000000',
                'hospital_ids' => [40, 64],
                'passes' => 2,
                'error_class' => 'Doctrine\DBAL\Exception\DeadlockException',
            ],
            $filter->filterLogAttributes([
                'sentry.environment' => 'beta',
                'run_id' => '0199e5a0-1c2b-7000-8000-000000000000',
                'hospital_ids' => [40, 64],
                'passes' => 2,
                'error_class' => 'Doctrine\DBAL\Exception\DeadlockException',
                'hospital_name' => 'Klinikum Beispiel',
                'phase' => '40 2026-01-05',
                'status' => 'closed from 2026-01-05 08:00',
                'error_message' => 'SQLSTATE[23505]: duplicate key',
            ]),
        );
    }

    public function testClosureTransactionsDropForeignSpansDescriptionsAndUnknownData(): void
    {
        $filter = new SentryClosureRebuildFilter();
        $event = Event::createTransaction();
        $event->setTransaction('closure_rebuild');
        $event->setTags(['trigger' => 'console', 'route' => '/admin/hospital/Klinikum']);
        $partition = new Span(SpanContext::make()->setOp('closure.rebuild.partition')->setDescription('2026-01-05')->setData([
            'hospital_id' => 40,
            'events' => 12,
            'week' => '2026-01-05',
        ]));
        $query = new Span(SpanContext::make()->setOp('db.sql.query')->setDescription('SELECT name FROM hospital'));
        $event->setSpans([$partition, $query]);

        self::assertTrue($filter->isClosureTransaction($event));
        $filtered = $filter->filterTransaction($event);

        self::assertSame([$partition], $filtered->getSpans());
        self::assertNull($partition->getDescription());
        self::assertSame(['hospital_id' => 40, 'events' => 12, 'week' => '[Filtered]'], $partition->getData());
        self::assertSame(['trigger' => 'console'], $filtered->getTags());
    }
}
