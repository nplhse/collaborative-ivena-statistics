<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Monitoring;

use App\Shared\Infrastructure\Monitoring\Sentry\SentryEventScrubber;
use App\Shared\Infrastructure\Monitoring\Sentry\SentryJobRunFilter;
use App\Shared\Infrastructure\Monitoring\Sentry\SentryLogScrubber;
use App\Statistics\ClosureAnalytics\Infrastructure\Monitoring\ClosureRebuildSentryProfile;
use PHPUnit\Framework\TestCase;
use Sentry\Logs\Log;
use Sentry\Logs\LogLevel;

final class SentryLogScrubberTest extends TestCase
{
    private SentryLogScrubber $scrubber;

    #[\Override]
    protected function setUp(): void
    {
        $this->scrubber = new SentryLogScrubber(new SentryEventScrubber(), new SentryJobRunFilter([new ClosureRebuildSentryProfile()]));
    }

    public function testAllowsImportSummary(): void
    {
        $log = $this->createLog('import.summary', ['total' => 10, 'ok' => 9, 'rejected' => 1]);

        self::assertInstanceOf(Log::class, $this->scrubber->scrubLog($log));
    }

    public function testDropsRowRejectLogs(): void
    {
        $log = $this->createLog('reject.row_rejected', ['line' => 12, 'messages' => ['invalid']]);

        self::assertNull($this->scrubber->scrubLog($log));
    }

    public function testDropsUnknownImportInfoLogs(): void
    {
        $log = $this->createLog('import.debug.step', ['import_id' => 42]);

        self::assertNull($this->scrubber->scrubLog($log));
    }

    public function testAllowsMainChannelWarningLogs(): void
    {
        $log = $this->createLog('upload.failed', ['reason' => 'disk full']);

        self::assertInstanceOf(Log::class, $this->scrubber->scrubLog($log));
    }

    public function testScrubsSensitiveAttributes(): void
    {
        $log = $this->createLog('import.failed.precondition', [
            'import_id' => 7,
            'reason' => '/var/imports/secret.csv missing',
            'path' => '/var/imports/secret.csv',
        ]);

        $scrubbed = $this->scrubber->scrubLog($log);
        self::assertInstanceOf(Log::class, $scrubbed);

        $attributes = $scrubbed->attributes()->toSimpleArray();
        self::assertSame(7, $attributes['import_id']);
        self::assertSame('[Filtered]', $attributes['path']);
        self::assertSame('[Filtered]', $attributes['reason']);
    }

    public function testDropsClosureRowRejectLogs(): void
    {
        $log = $this->createLog('closure.reject.row_rejected', ['line' => 12, 'department' => 'Kardiologie']);

        self::assertNull($this->scrubber->scrubLog($log));
    }

    public function testFailedLoginsKeepTheHashButLoseIpAndUserAgent(): void
    {
        $log = $this->createLog('security.login.failure', [
            'username_hash' => 'abc123',
            'ip' => '192.0.2.7',
            'user_agent' => 'Mozilla/5.0',
            'exception' => 'BadCredentialsException',
        ]);

        $scrubbed = $this->scrubber->scrubLog($log);
        self::assertInstanceOf(Log::class, $scrubbed);

        $attributes = $scrubbed->attributes()->toSimpleArray();
        self::assertSame('abc123', $attributes['username_hash']);
        self::assertSame('[Filtered]', $attributes['ip']);
        self::assertSame('[Filtered]', $attributes['user_agent']);
    }

    public function testDatabaseValuesAreRemovedFromBodyAndAttributes(): void
    {
        $error = "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value\nDETAIL:  Key (email)=(someone@example.test) already exists.";
        $log = $this->createLog('Refresh failed: '.$error, ['message' => $error, 'exception' => 'UniqueConstraintViolationException']);

        $scrubbed = $this->scrubber->scrubLog($log);
        self::assertInstanceOf(Log::class, $scrubbed);

        self::assertStringNotContainsString('someone@example.test', $scrubbed->getBody());
        self::assertStringNotContainsString('someone@example.test', (string) $scrubbed->attributes()->toSimpleArray()['message']);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createLog(string $message, array $attributes): Log
    {
        $log = new Log(
            microtime(true),
            'trace-id',
            LogLevel::info(),
            $message,
        );

        foreach ($attributes as $key => $value) {
            $log->setAttribute((string) $key, $value);
        }

        return $log;
    }
}
