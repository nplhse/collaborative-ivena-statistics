<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Monitoring;

use App\Shared\Infrastructure\Monitoring\Sentry\SentryEventScrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventId;
use Sentry\ExceptionDataBag;
use Sentry\Severity;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;

final class SentryEventScrubberTest extends TestCase
{
    private SentryEventScrubber $scrubber;

    #[\Override]
    protected function setUp(): void
    {
        $this->scrubber = new SentryEventScrubber();
    }

    public function testScrubEventRemovesSensitiveExtraKeys(): void
    {
        $event = Event::createEvent(EventId::generate());
        $event->setExtra([
            'import_id' => 42,
            'row' => ['patient' => 'secret'],
            'password' => 'secret',
        ]);

        $scrubbed = $this->scrubber->scrubEvent($event);

        self::assertSame(42, $scrubbed->getExtra()['import_id']);
        self::assertSame('[Filtered]', $scrubbed->getExtra()['row']);
        self::assertSame('[Filtered]', $scrubbed->getExtra()['password']);
    }

    public function testScrubBreadcrumbDropsHttpBreadcrumbs(): void
    {
        $breadcrumb = new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_HTTP,
            'http',
            'GET /statistics',
            ['request_body' => '{"secret":"value"}'],
        );

        self::assertNull($this->scrubber->scrubBreadcrumb($breadcrumb));
    }

    public function testScrubEventRemovesRequestBody(): void
    {
        $event = Event::createEvent(EventId::generate());
        $event->setLevel(Severity::error());
        $event->setRequest([
            'url' => 'https://example.test/statistics',
            'method' => 'POST',
            'data' => ['comment' => 'sensitive'],
            'query_string' => 'token=abc',
        ]);

        $scrubbed = $this->scrubber->scrubEvent($event);

        self::assertArrayNotHasKey('data', $scrubbed->getRequest());
        self::assertArrayNotHasKey('query_string', $scrubbed->getRequest());
    }

    public function testScrubArrayKeepsNumericKeys(): void
    {
        $scrubbed = $this->scrubber->scrubArray([
            0 => 'first',
            1 => ['nested' => 'value'],
        ]);

        self::assertSame('first', $scrubbed[0]);
        self::assertSame(['nested' => 'value'], $scrubbed[1]);
    }

    public function testScrubEventStripsQueryAndFragmentFromTheRequestUrl(): void
    {
        $event = Event::createEvent(EventId::generate());
        $event->setRequest([
            'url' => 'https://example.test/login?email=probe%40example.org&token=abc123#top',
            'method' => 'GET',
            'headers' => [
                'referer' => ['https://example.test/statistics?hospital=7&period=2026'],
                'user-agent' => ['Probe/1.0 (compatible; ?x=1)'],
            ],
        ]);

        $request = $this->scrubber->scrubEvent($event)->getRequest();

        self::assertSame('https://example.test/login', $request['url']);
        self::assertSame(['https://example.test/statistics'], $request['headers']['referer']);
        self::assertSame(['Probe/1.0 (compatible; ?x=1)'], $request['headers']['user-agent'], 'Only URL keys are cut.');
    }

    public function testScrubEventKeepsTraceDataAnObjectAndFiltersItsKeys(): void
    {
        $event = Event::createTransaction();
        $event->setContext('trace', [
            'trace_id' => '566e3688a61d4bc888951642d6f14a19',
            'span_id' => '566e3688a61d4bc8',
            'op' => 'http.server',
            'data' => [
                'http.request.method' => 'GET',
                'http.url' => 'https://example.test/statistics/explorer?hospital=7&email=a%40b.c',
                'route' => 'app_statistics_explorer',
                'http.response.status_code' => 200,
                'net.peer.ip' => '203.0.113.9',
                'custom.secret' => 'value',
                'sentry.origin' => 'auto.http.server',
            ],
        ]);

        $trace = $this->scrubber->scrubEvent($event)->getContexts()['trace'];

        self::assertSame('566e3688a61d4bc888951642d6f14a19', $trace['trace_id']);
        self::assertSame([
            'http.request.method' => 'GET',
            'http.url' => 'https://example.test/statistics/explorer',
            'route' => 'app_statistics_explorer',
            'http.response.status_code' => 200,
            'sentry.origin' => 'auto.http.server',
        ], $trace['data']);
    }

    public function testScrubEventLeavesAllowlistedJobRunTraceDataAlone(): void
    {
        $event = Event::createTransaction();
        $event->setContext('trace', ['op' => 'closure.rebuild', 'data' => ['hospital_ids' => [3, 7], 'rows_written' => 12]]);

        $trace = $this->scrubber->scrubEvent($event, traceDataAllowlisted: true)->getContexts()['trace'];

        self::assertSame(['hospital_ids' => [3, 7], 'rows_written' => 12], $trace['data']);
    }

    public function testScrubEventFiltersSpanDataAndHttpSpanDescriptions(): void
    {
        $query = new Span(SpanContext::make()->setOp('db.sql.query')->setDescription('SELECT * FROM hospital WHERE id = ?')->setData([
            'db.system' => 'postgresql',
            'db.user' => 'app',
            'server.address' => 'database',
        ]));
        $client = new Span(SpanContext::make()->setOp('http.client')->setDescription('GET https://api.example.test/route?key=secret')->setData([
            'http.url' => 'https://api.example.test/route',
            'http.query' => 'key=secret',
            'http.response.status_code' => 200,
        ]));
        $event = Event::createTransaction();
        $event->setSpans([$query, $client]);

        $this->scrubber->scrubEvent($event);

        self::assertSame('SELECT * FROM hospital WHERE id = ?', $query->getDescription());
        self::assertSame(['db.system' => 'postgresql', 'db.user' => '[Filtered]', 'server.address' => '[Filtered]'], $query->getData());
        self::assertSame('GET https://api.example.test/route', $client->getDescription());
        self::assertSame('[Filtered]', $client->getData()['http.query']);
        self::assertSame(200, $client->getData()['http.response.status_code']);
    }

    public function testScrubArrayCutsQueriesOnlyFromUrlKeys(): void
    {
        $scrubbed = $this->scrubber->scrubArray([
            'request_uri' => '/statistics?hospital=7',
            'url.full' => 'https://example.test/a?b=c',
            'security_note' => 'why?not',
            'title' => 'What? #1',
        ]);

        self::assertSame('/statistics', $scrubbed['request_uri']);
        self::assertSame('https://example.test/a', $scrubbed['url.full']);
        self::assertSame('why?not', $scrubbed['security_note']);
        self::assertSame('What? #1', $scrubbed['title']);
    }

    /** Synthetic values only; the text is what PostgreSQL and DBAL produce for a unique violation (#1577). */
    private const string UNIQUE_VIOLATION = "An exception occurred while executing a query: SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"uniq_identifier_email\"\nDETAIL:  Key (email)=(someone@example.test) already exists.";

    public function testDatabaseValuesAreRemovedFromExceptionMessages(): void
    {
        $event = Event::createEvent(EventId::generate());
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException(self::UNIQUE_VIOLATION))]);

        $value = $this->scrubber->scrubEvent($event)->getExceptions()[0]->getValue();

        self::assertStringNotContainsString('someone@example.test', $value);
        self::assertStringContainsString('uniq_identifier_email', $value, 'the constraint name stays');
        self::assertStringContainsString('Key (email)=([Filtered])', $value, 'the column names stay');
    }

    public function testOtherDatabaseDetailsAreRemovedAsWell(): void
    {
        $messages = [
            "SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \"arrival_at\"\nDETAIL:  Failing row contains (17, 4711, Musterstadt, null).",
            "SQLSTATE[23503]: Foreign key violation: 7 ERROR:  insert or update violates foreign key constraint\nDETAIL:  Some other text quoting 4711.",
            'An exception occurred while executing a query with params ["4711", "someone@example.test"]: SQLSTATE[42P01]',
        ];

        foreach ($messages as $message) {
            $scrubbed = $this->scrubber->scrubMessage($message);
            self::assertStringNotContainsString('4711', $scrubbed, $message);
            self::assertStringNotContainsString('someone@example.test', $scrubbed, $message);
        }

        self::assertSame('Nothing to remove.', $this->scrubber->scrubMessage('Nothing to remove.'));
        self::assertSame(1025, mb_strlen($this->scrubber->scrubMessage(str_repeat('x', 2000))), 'cut to 1024 plus the ellipsis');
    }

    public function testTheEventMessageLosesValuesAndParameters(): void
    {
        $event = Event::createEvent(EventId::generate());
        $event->setMessage('Import of %s failed: '.self::UNIQUE_VIOLATION, ['someone@example.test'], 'Import of someone@example.test failed: '.self::UNIQUE_VIOLATION);

        $scrubbed = $this->scrubber->scrubEvent($event);

        self::assertStringNotContainsString('someone@example.test', (string) $scrubbed->getMessage());
        self::assertStringNotContainsString('someone@example.test', (string) $scrubbed->getMessageFormatted());
        self::assertSame([], $scrubbed->getMessageParams());
    }

    public function testDatabaseValuesAreRemovedFromExtraAndContexts(): void
    {
        $event = Event::createEvent(EventId::generate());
        $event->setExtra(['message' => self::UNIQUE_VIOLATION]);
        $event->setContext('job', ['error' => self::UNIQUE_VIOLATION]);

        $scrubbed = $this->scrubber->scrubEvent($event);

        self::assertStringNotContainsString('someone@example.test', (string) $scrubbed->getExtra()['message']);
        self::assertStringNotContainsString('someone@example.test', (string) $scrubbed->getContexts()['job']['error']);
    }

    public function testIpUserAgentAndRawValuesAreFilteredByExactKey(): void
    {
        $scrubbed = $this->scrubber->scrubArray([
            'ip' => '192.0.2.7',
            'client_ip' => '192.0.2.7',
            'user_agent' => 'Mozilla/5.0',
            'raw_value' => 'Schockraum',
            'recipient_id' => 12,
            'description' => 'kept',
            'username_hash' => 'abc123',
        ]);

        self::assertSame('[Filtered]', $scrubbed['ip']);
        self::assertSame('[Filtered]', $scrubbed['client_ip']);
        self::assertSame('[Filtered]', $scrubbed['user_agent']);
        self::assertSame('[Filtered]', $scrubbed['raw_value']);
        self::assertSame(12, $scrubbed['recipient_id'], 'contains "ip" but is not the key ip');
        self::assertSame('kept', $scrubbed['description']);
        self::assertSame('abc123', $scrubbed['username_hash'], 'hashed identifiers stay for #1487');
    }

    public function testBreadcrumbMessagesLoseDatabaseValues(): void
    {
        $breadcrumb = new Breadcrumb(Breadcrumb::LEVEL_ERROR, Breadcrumb::TYPE_ERROR, 'error_reporting', self::UNIQUE_VIOLATION);

        $scrubbed = $this->scrubber->scrubBreadcrumb($breadcrumb);

        self::assertInstanceOf(Breadcrumb::class, $scrubbed);
        self::assertStringNotContainsString('someone@example.test', (string) $scrubbed->getMessage());
    }
}
