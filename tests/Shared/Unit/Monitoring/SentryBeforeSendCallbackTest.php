<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Monitoring;

use App\Shared\Infrastructure\Monitoring\Sentry\SentryBeforeSendCallback;
use App\Shared\Infrastructure\Monitoring\Sentry\SentryEventScrubber;
use App\Shared\Infrastructure\Monitoring\Sentry\SentryJobRunFilter;
use App\Shared\Infrastructure\Monitoring\Sentry\SentryLogScrubber;
use App\Statistics\ClosureAnalytics\Infrastructure\Monitoring\ClosureRebuildSentryProfile;
use PHPUnit\Framework\TestCase;
use Sentry\Event;

final class SentryBeforeSendCallbackTest extends TestCase
{
    public function testRequestTransactionsGetTheGenericTraceDataAllowlist(): void
    {
        $beforeSend = $this->sentryCallback()->getBeforeSendTransaction();

        $page = Event::createTransaction();
        $page->setTransaction('GET app_statistics_explorer');
        $page->setContext('trace', ['data' => ['route' => 'app_statistics_explorer', 'hospital_ids' => [7]]]);

        $sent = $beforeSend($page);

        self::assertInstanceOf(Event::class, $sent);
        self::assertSame(['route' => 'app_statistics_explorer'], $sent->getContexts()['trace']['data']);
        self::assertNull($beforeSend(null));
    }

    public function testJobRunTransactionsKeepTheirProfileData(): void
    {
        $beforeSend = $this->sentryCallback()->getBeforeSendTransaction();

        $run = Event::createTransaction();
        $run->setTransaction('closure_rebuild');
        $run->setContext('trace', ['data' => ['hospital_ids' => [3, 7], 'passes' => 2, 'hospital_name' => 'Klinikum']]);

        $sent = $beforeSend($run);

        self::assertInstanceOf(Event::class, $sent);
        self::assertSame(['hospital_ids' => [3, 7], 'passes' => 2], $sent->getContexts()['trace']['data']);
    }

    private function sentryCallback(): SentryBeforeSendCallback
    {
        $scrubber = new SentryEventScrubber();
        $jobRunFilter = new SentryJobRunFilter([new ClosureRebuildSentryProfile()]);

        return new SentryBeforeSendCallback($scrubber, new SentryLogScrubber($scrubber, $jobRunFilter), $jobRunFilter);
    }
}
