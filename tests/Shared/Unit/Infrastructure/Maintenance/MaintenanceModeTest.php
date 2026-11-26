<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Maintenance;

use App\Shared\Application\Health\HealthCheckStatus;
use App\Shared\Infrastructure\Maintenance\MaintenanceMode;
use App\Shared\Infrastructure\Monitoring\Health\MaintenanceHealthCheck;
use PHPUnit\Framework\TestCase;

final class MaintenanceModeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/maintenance-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->directory.'/sub/enabled');
        @rmdir($this->directory.'/sub');
        @rmdir($this->directory);
    }

    public function testSwitchesOnAndOffAndCreatesTheDirectory(): void
    {
        $mode = new MaintenanceMode($this->directory.'/sub/enabled');
        $health = new MaintenanceHealthCheck($mode);

        self::assertFalse($mode->isEnabled());
        self::assertNull($mode->enabledSince());
        self::assertSame(HealthCheckStatus::Healthy, $health->check()->status);

        $mode->enable();
        self::assertTrue($mode->isEnabled());
        self::assertFileExists($this->directory.'/sub/enabled');
        self::assertSame(HealthCheckStatus::Degraded, $health->check()->status);
        self::assertSame('on', $health->check()->value);

        $mode->disable();
        self::assertFalse($mode->isEnabled());
        $mode->disable();
        self::assertSame('off', $health->check()->value);
    }

    public function testSwitchingOnAgainKeepsTheStartTime(): void
    {
        $mode = new MaintenanceMode($this->directory.'/sub/enabled');
        $mode->enable();
        touch($this->directory.'/sub/enabled', 1_764_150_000);

        $mode->enable();

        self::assertSame(1_764_150_000, $mode->enabledSince()?->getTimestamp());
    }
}
