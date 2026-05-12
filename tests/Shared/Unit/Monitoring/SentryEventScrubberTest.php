<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Monitoring;

use App\Shared\Infrastructure\Monitoring\Sentry\SentryEventScrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventId;
use Sentry\Severity;

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
}
