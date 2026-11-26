<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Monitoring;

use App\Shared\Infrastructure\Monitoring\Sentry\SentryMonitoringSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SentryMonitoringSettingsTest extends TestCase
{
    public function testDisabledWithoutDsn(): void
    {
        foreach ([null, '', '  '] as $dsn) {
            $settings = new SentryMonitoringSettings($dsn, 'eu');
            self::assertFalse($settings->isEnabled());
            self::assertNull($settings->location());
        }
    }

    #[DataProvider('dsnHosts')]
    public function testDerivesTheLocationFromTheDsnHost(string $dsn, string $location): void
    {
        self::assertSame($location, new SentryMonitoringSettings($dsn)->location());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dsnHosts(): iterable
    {
        yield 'EU region' => ['https://abc@o123.ingest.de.sentry.io/456', 'eu'];
        yield 'US region' => ['https://abc@o123.ingest.us.sentry.io/456', 'us'];
        yield 'US default host' => ['https://abc@o123.ingest.sentry.io/456', 'us'];
        yield 'self-hosted' => ['https://abc@sentry.example.org/7', 'self_hosted'];
    }

    public function testAConfiguredLocationWins(): void
    {
        self::assertSame('eu', new SentryMonitoringSettings('https://abc@sentry.example.org/7', 'EU')->location());
        self::assertSame('self_hosted', new SentryMonitoringSettings('https://abc@sentry.example.org/7', 'unknown')->location(), 'Unknown values fall back to the DSN host.');
    }
}
