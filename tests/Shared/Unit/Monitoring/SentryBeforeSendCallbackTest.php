<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Monitoring;

use App\Shared\Infrastructure\Monitoring\Sentry\SentryBeforeSendCallback;
use App\Shared\Infrastructure\Monitoring\Sentry\SentryClosureRebuildFilter;
use App\Shared\Infrastructure\Monitoring\Sentry\SentryEventScrubber;
use App\Shared\Infrastructure\Monitoring\Sentry\SentryLogScrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Event;

final class SentryBeforeSendCallbackTest extends TestCase
{
    public function testDropsTransactionsOfThePollingEndpoint(): void
    {
        $beforeSend = $this->sentryCallback()->getBeforeSendTransaction();

        $poll = Event::createTransaction();
        $poll->setTransaction('GET app_notification_poll');
        $page = Event::createTransaction();
        $page->setTransaction('GET app_notification_index');

        self::assertNull($beforeSend($poll));
        self::assertSame($page, $beforeSend($page));
        self::assertNull($beforeSend(null));
    }

    private function sentryCallback(): SentryBeforeSendCallback
    {
        $scrubber = new SentryEventScrubber();
        $closureRebuildFilter = new SentryClosureRebuildFilter();

        return new SentryBeforeSendCallback($scrubber, new SentryLogScrubber($scrubber, $closureRebuildFilter), $closureRebuildFilter);
    }
}
