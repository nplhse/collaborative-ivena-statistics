<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Application\MessageHandler;

use App\Content\Application\Contract\MediaIntegrityCheckerInterface;
use App\Content\Application\Media\MediaIntegrityReport;
use App\Content\Application\Media\MediaMaintenanceException;
use App\Content\Application\Message\CheckMediaIntegrityMessage;
use App\Content\Application\MessageHandler\CheckMediaIntegrityMessageHandler;
use App\Shared\Application\JobRun\CurrentJobRunInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CheckMediaIntegrityMessageHandlerTest extends TestCase
{
    /** @var array<string, int|bool|string|null> */
    private array $metrics = [];
    private ?string $skipped = null;

    public function testRecordsTheCountsOfTheRunAsMetrics(): void
    {
        $report = new MediaIntegrityReport(
            new \DateTimeImmutable('2026-11-21 05:10'),
            false,
            12,
            4096,
            5,
            ['originals/2026/11/a.png'],
            ['originals/2026/11/b.png', 'originals/2026/11/c.png'],
            ['originals/2026/10/d.png'],
            ['originals/2026/11/e.png', 'originals/2026/11/f.png', 'originals/2026/11/g.png'],
            4,
            ['notes.txt'],
            null,
        );

        $this->handler(static fn (): MediaIntegrityReport => $report)(new CheckMediaIntegrityMessage());

        self::assertSame([
            'files' => 12,
            'hashed' => 5,
            'missing' => 1,
            'damaged' => 2,
            'orphans_deleted' => 1,
            'orphans_kept' => 3,
            'leftovers_deleted' => 4,
            'unexpected' => 1,
        ], $this->metrics);
        self::assertNull($this->skipped);
    }

    public function testARunThatMeetsABackupIsSkipped(): void
    {
        $this->handler(static fn (): never => throw new MediaMaintenanceException('busy'))(new CheckMediaIntegrityMessage());

        self::assertSame('maintenance_running', $this->skipped);
        self::assertSame([], $this->metrics);
    }

    /**
     * @param \Closure(): MediaIntegrityReport $check
     */
    private function handler(\Closure $check): CheckMediaIntegrityMessageHandler
    {
        $checker = $this->createStub(MediaIntegrityCheckerInterface::class);
        $checker->method('check')->willReturnCallback($check);
        $jobRun = $this->createStub(CurrentJobRunInterface::class);
        $jobRun->method('addMetrics')->willReturnCallback(function (array $metrics): void {
            $this->metrics = [...$this->metrics, ...$metrics];
        });
        $jobRun->method('markSkipped')->willReturnCallback(function (string $reason): void {
            $this->skipped = $reason;
        });

        return new CheckMediaIntegrityMessageHandler($checker, $jobRun, new NullLogger());
    }
}
