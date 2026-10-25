<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Monitoring;

use App\Import\Infrastructure\Monitoring\ImportRunSentryProfile;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunSentryProfile;
use App\Shared\Infrastructure\Monitoring\Sentry\SentryJobRunFilter;
use App\Statistics\ClosureAnalytics\Infrastructure\Monitoring\ClosureRebuildSentryProfile;
use App\Statistics\Infrastructure\Monitoring\AllocationProjectionSentryProfile;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;

final class SentryJobRunFilterTest extends TestCase
{
    public function testClosureLogsKeepOnlyIdsCountsAndSdkAttributes(): void
    {
        $filter = $this->filter();

        self::assertTrue($filter->isJobRunLog('closure_rebuild.summary'));
        self::assertTrue($filter->isJobRunLog('closure_volume.late_requests'));
        self::assertFalse($filter->isJobRunLog('import.summary'));
        self::assertSame(
            [
                'sentry.environment' => 'beta',
                'run_id' => '0199e5a0-1c2b-7000-8000-000000000000',
                'hospital_ids' => [40, 64],
                'passes' => 2,
                'error_class' => \Doctrine\DBAL\Exception\DeadlockException::class,
            ],
            $filter->filterLogAttributes('closure_rebuild.summary', [
                'sentry.environment' => 'beta',
                'run_id' => '0199e5a0-1c2b-7000-8000-000000000000',
                'hospital_ids' => [40, 64],
                'passes' => 2,
                'error_class' => \Doctrine\DBAL\Exception\DeadlockException::class,
                'hospital_name' => 'Klinikum Beispiel',
                'phase' => '40 2026-01-05',
                'status' => 'closed from 2026-01-05 08:00',
                'error_message' => 'SQLSTATE[23505]: duplicate key',
            ]),
        );
    }

    public function testEachJobUsesItsOwnAllowlist(): void
    {
        $filter = $this->filter();

        self::assertTrue($filter->isJobRunLog('test_job.summary'));
        self::assertSame(
            ['rows' => 12],
            $filter->filterLogAttributes('test_job.summary', ['rows' => 12, 'passes' => 2]),
        );
        self::assertSame(['passes' => 2], $filter->filterLogAttributes('closure_rebuild.summary', ['rows' => 12, 'passes' => 2]));
    }

    public function testOtherLogsAndTransactionsStayUntouched(): void
    {
        $filter = $this->filter();
        self::assertSame(['msg' => 'free text'], $filter->filterLogAttributes('import.summary', ['msg' => 'free text']));

        $event = Event::createTransaction();
        $event->setTransaction('GET app_default_index');
        $event->setTags(['route' => 'app_default_index']);
        $query = new Span(SpanContext::make()->setOp('db.sql.query')->setDescription('SELECT 1'));
        $event->setSpans([$query]);

        $filtered = $filter->filterTransaction($event);

        self::assertSame([$query], $filtered->getSpans());
        self::assertSame('SELECT 1', $query->getDescription());
        self::assertSame(['route' => 'app_default_index'], $filtered->getTags());
    }

    public function testClosureTransactionsDropForeignSpansDescriptionsAndUnknownData(): void
    {
        $filter = $this->filter();
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

        $filtered = $filter->filterTransaction($event);

        self::assertSame([$partition], $filtered->getSpans());
        self::assertNull($partition->getDescription());
        self::assertSame(['hospital_id' => 40, 'events' => 12, 'week' => '[Filtered]'], $partition->getData());
        self::assertSame(['trigger' => 'console'], $filtered->getTags());
    }

    public function testImportRunLogsAndTransactionsKeepOnlyIdsAndCounts(): void
    {
        $filter = $this->filter();

        self::assertTrue($filter->isJobRunLog('import.run.summary'));
        self::assertTrue($filter->isJobRunLog('import.run.threshold_exceeded'));
        self::assertTrue($filter->isJobRunLog('import.run.retries_exhausted'));
        self::assertFalse($filter->isJobRunLog('import.run.retryable_error'), 'Other import logs keep the generic scrubber.');
        self::assertSame(
            ['import_id' => 7, 'hospital_id' => 40, 'job' => 'allocation_import', 'rows_total' => 120, 'read_ms' => 31, 'error_class' => \RuntimeException::class],
            $filter->filterLogAttributes('import.run.summary', [
                'import_id' => 7,
                'hospital_id' => 40,
                'job' => 'allocation_import',
                'rows_total' => 120,
                'read_ms' => 31,
                'error_class' => \RuntimeException::class,
                'msg' => 'SQLSTATE: Klinikum Beispiel',
                'reason' => 'Datei /var/imports/2026/10/klinikum.csv fehlt',
                'file' => 'klinikum.csv',
                'hospital' => 'Klinikum Beispiel',
            ]),
        );

        $event = Event::createTransaction();
        $event->setTransaction('import_run');
        $event->setTags(['job' => 'allocation_import', 'trigger' => 'upload', 'file' => '/var/imports/klinikum beispiel.csv']);
        $read = new Span(SpanContext::make()->setOp('import.read')->setDescription('klinikum.csv')->setData(['ms' => 31, 'summed' => true, 'path' => '/var/imports/x.csv']));
        $query = new Span(SpanContext::make()->setOp('db.sql.query')->setDescription('INSERT INTO allocation'));
        $event->setSpans([$read, $query]);

        $filtered = $filter->filterTransaction($event);

        self::assertSame([$read], $filtered->getSpans());
        self::assertNull($read->getDescription());
        self::assertSame(['ms' => 31, 'summed' => true, 'path' => '[Filtered]'], $read->getData());
        self::assertSame(['job' => 'allocation_import', 'trigger' => 'upload'], $filtered->getTags());
    }

    public function testProjectionRebuildLogsKeepOnlyIdsCountsAndDurations(): void
    {
        $filter = $this->filter();

        self::assertTrue($filter->isJobRunLog('projection_rebuild.summary'));
        self::assertSame(
            ['import_id' => 7, 'rows_written' => 420, 'mv_refreshed' => true, 'insert_ms' => 88, 'error_class' => \RuntimeException::class],
            $filter->filterLogAttributes('projection_rebuild.failed', [
                'import_id' => 7,
                'rows_written' => 420,
                'mv_refreshed' => true,
                'insert_ms' => 88,
                'error_class' => \RuntimeException::class,
                'message' => 'SQLSTATE: duplicate key (hospital)=(Klinikum Beispiel)',
            ]),
        );

        $event = Event::createTransaction();
        $event->setTransaction('allocation_projection_rebuild');
        $insert = new Span(SpanContext::make()->setOp('projection.insert')->setDescription('INSERT INTO allocation_stats_projection'));
        $query = new Span(SpanContext::make()->setOp('db.sql.query')->setDescription('INSERT INTO allocation_stats_projection'));
        $event->setSpans([$insert, $query]);

        self::assertSame([$insert], $filter->filterTransaction($event)->getSpans());
        self::assertNull($insert->getDescription());
    }

    private function filter(): SentryJobRunFilter
    {
        $testJob = new class implements JobRunSentryProfile {
            #[\Override]
            public function transaction(): string
            {
                return 'test_job';
            }

            #[\Override]
            public function sampledOps(): array
            {
                return ['test.job'];
            }

            #[\Override]
            public function spanOpPrefix(): string
            {
                return 'test.job';
            }

            #[\Override]
            public function logPrefixes(): array
            {
                return ['test_job.'];
            }

            #[\Override]
            public function allowedKeys(): array
            {
                return ['rows'];
            }
        };

        return new SentryJobRunFilter([new ClosureRebuildSentryProfile(), new ImportRunSentryProfile(), new AllocationProjectionSentryProfile(), $testJob]);
    }
}
