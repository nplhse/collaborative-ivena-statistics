<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Monitoring;

use App\Shared\Infrastructure\Monitoring\JobRun\Entity\JobRun;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The read-only entity must hydrate every row JobRunRecorder writes, so the admin list keeps working
 * when the recorder or the table changes.
 */
#[ResetDatabase]
final class JobRunEntityTest extends KernelTestCase
{
    public function testARecordedRunHydratesWithAllColumns(): void
    {
        $recorder = new JobRunRecorder(
            self::getContainer()->get('doctrine.dbal.default_connection'),
            new MockClock('2026-10-25 16:00:00', 'UTC'),
            new NullLogger(),
        );
        $recorder->start('run-1', 'allocation_import', 'upload', attempt: 2, importId: 12, importRunId: '0199f0a4-7d3c-7a51-9b1e-4f2a6c8d9e01', hospitalId: 40);
        $recorder->finish('run-1', 'allocation_import', 'failed', 1234, 5_000_000_000, ['slow', 'memory'], ['rows_total' => 12, 'read_ms' => 30], new \RuntimeException('Broken row'));

        $run = $this->entityManager()->find(JobRun::class, 'run-1');

        self::assertInstanceOf(JobRun::class, $run);
        self::assertSame('allocation_import', $run->getJob());
        self::assertSame('upload', $run->getTriggeredBy());
        self::assertSame('failed', $run->getStatus());
        self::assertSame(2, $run->getAttempt());
        self::assertSame(12, $run->getImportId());
        self::assertSame('0199f0a4-7d3c-7a51-9b1e-4f2a6c8d9e01', $run->getImportRunId());
        self::assertSame(40, $run->getHospitalId());
        self::assertSame('2026-10-25 16:00:00', $run->getStartedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertInstanceOf(\DateTimeImmutable::class, $run->getFinishedAt());
        self::assertSame(1234, $run->getDurationMs());
        self::assertSame(5_000_000_000, $run->getPeakMemoryBytes());
        self::assertSame(['slow', 'memory'], $run->getThresholdsExceededList());
        self::assertSame(\RuntimeException::class, $run->getErrorClass());
        self::assertSame('Broken row', $run->getErrorMessage());
        $metrics = $run->getMetrics();
        ksort($metrics);
        self::assertSame(['read_ms' => 30, 'rows_total' => 12], $metrics);
    }

    public function testARunningRunHasNoEndValues(): void
    {
        $recorder = new JobRunRecorder(
            self::getContainer()->get('doctrine.dbal.default_connection'),
            new MockClock(),
            new NullLogger(),
        );
        $recorder->start('run-2', 'closure_rebuild', 'console', ['full' => true]);

        $run = $this->entityManager()->find(JobRun::class, 'run-2');

        self::assertInstanceOf(JobRun::class, $run);
        self::assertSame('running', $run->getStatus());
        self::assertNull($run->getFinishedAt());
        self::assertNull($run->getDurationMs());
        self::assertNull($run->getPeakMemoryBytes());
        self::assertSame([], $run->getThresholdsExceededList());
        self::assertSame(['full' => true], $run->getMetrics());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
