<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\ClosureAnalytics;

use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * A writer pauses closure rebuilds while it deletes and writes closure data (the generator, #791): the worker of
 * another process waits for the lock, and a rebuild requested in the pausing process stays open.
 */
#[ResetDatabase]
final class ClosureRebuildPauseTest extends KernelTestCase
{
    /** The lock of ClosureVolumeProjectionRebuild, which every closure rebuild takes. */
    private const string LOCK = 'closure-volume-projection';

    public function testOtherHoldersCannotTakeTheLockDuringThePause(): void
    {
        self::bootKernel();
        $locks = self::getContainer()->get(LockFactory::class);

        $acquiredDuringPause = $this->rebuild()->whileRebuildsPaused(static function () use ($locks): bool {
            $lock = $locks->createLock(self::LOCK, null);
            $acquired = $lock->acquire(false);
            if ($acquired) {
                $lock->release();
            }

            return $acquired;
        });

        self::assertFalse($acquiredDuringPause);
        $after = $locks->createLock(self::LOCK, null);
        self::assertTrue($after->acquire(false), 'The pause releases the lock.');
        $after->release();
    }

    public function testARebuildRequestedDuringThePauseStaysOpenUntilAfterwards(): void
    {
        self::bootKernel();
        $rebuild = $this->rebuild();

        $rebuild->whileRebuildsPaused(function () use ($rebuild): void {
            $this->insertOpenRequest('analysis', 40);
            // With synchronous messages (tests) the handler runs here, in the pausing process.
            $rebuild->consume();
        });

        self::assertSame(1, $this->openRequests(), 'consume() during the pause leaves the request open.');
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM job_run'));

        $rebuild->consume();
        self::assertSame(0, $this->openRequests());
    }

    public function testTheProcessThatPausesCanStillRebuildAHospital(): void
    {
        self::bootKernel();
        $rebuild = $this->rebuild();

        $rebuild->whileRebuildsPaused(function () use ($rebuild): void {
            $this->insertOpenRequest('analysis', 40);
            $this->insertOpenRequest('volume', 64);
            $rebuild->rebuild(hospitalIds: [40]);
        });

        // The rebuild of hospital 40 claimed its request; the one of hospital 64 is left for its own rebuild.
        self::assertSame([64], array_map(intval(...), $this->connection()->fetchFirstColumn('SELECT hospital_id FROM closure_rebuild_request WHERE consumed_at IS NULL')));
    }

    public function testPausesNest(): void
    {
        self::bootKernel();
        $rebuild = $this->rebuild();

        self::assertSame(3, $rebuild->whileRebuildsPaused(static fn (): int => $rebuild->whileRebuildsPaused(static fn (): int => 3)));
    }

    private function rebuild(): ClosureVolumeProjectionRebuildInterface
    {
        return self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class);
    }

    private function openRequests(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM closure_rebuild_request WHERE consumed_at IS NULL');
    }

    private function insertOpenRequest(string $kind, int $hospitalId): void
    {
        $this->connection()->insert('closure_rebuild_request', [
            'kind' => $kind,
            'hospital_id' => $hospitalId,
            'requested_at' => '2026-10-14 10:00:00',
        ]);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
