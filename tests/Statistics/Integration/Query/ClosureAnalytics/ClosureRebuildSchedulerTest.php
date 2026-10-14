<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\ClosureAnalytics;

use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
use App\Statistics\ClosureAnalytics\Infrastructure\Projection\ClosureRebuildScheduler;
use App\Statistics\ClosureAnalytics\Infrastructure\Projection\ClosureVolumeProjectionRebuilder;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class ClosureRebuildSchedulerTest extends KernelTestCase
{
    public function testRequestTimesAreStoredAsEuropeBerlinWallClock(): void
    {
        [$scheduler, $clock] = $this->scheduler('2026-10-14 15:58:55');
        $scheduler->requestAnalysis(40);
        $claim = $scheduler->claim('run-a', 'analysis', [40]);
        $clock->modify('+13 minutes');
        $scheduler->complete($claim->requestIds);

        self::assertSame(
            ['requested_at' => '2026-10-14 17:58:55', 'claimed_at' => '2026-10-14 17:58:55', 'consumed_at' => '2026-10-14 18:11:55'],
            $this->connection()->fetchAssociative('SELECT requested_at, claimed_at, consumed_at FROM closure_rebuild_request'),
        );
    }

    public function testAClaimStaysInProgressUntilItIsCompleted(): void
    {
        [$scheduler] = $this->scheduler('2026-10-14 10:00:00');
        $scheduler->requestAnalysis(40);
        $scheduler->requestAnalysis(64);

        $claim = $scheduler->claim('run-a', 'analysis', [40]);
        self::assertSame([40], $claim->hospitalIds);
        self::assertCount(1, $claim->requestIds);
        self::assertSame(0, $this->consumed());
        self::assertTrue($scheduler->claim('run-a', 'analysis', [40])->isEmpty(), 'A run does not claim its own request twice.');
        self::assertSame([64], $scheduler->claim('run-a', 'analysis')->hospitalIds);

        $scheduler->complete($claim->requestIds);
        self::assertSame(1, $this->consumed());
    }

    public function testAReleasedRequestIsClaimedAgain(): void
    {
        [$scheduler] = $this->scheduler('2026-10-14 10:00:00');
        $scheduler->requestVolume(null);
        $claim = $scheduler->claim('run-a', 'volume', [40]);
        self::assertTrue($claim->all);

        $scheduler->release($claim->requestIds, new \RuntimeException('deadlock'));

        $again = $scheduler->claim('run-b', 'volume', [40]);
        self::assertSame($claim->requestIds, $again->requestIds);
        self::assertSame(
            ['claim_attempts' => 2, 'claimed_by' => 'run-b', 'last_error' => 'RuntimeException: deadlock', 'failed_at' => null],
            $this->connection()->fetchAssociative('SELECT claim_attempts, claimed_by, last_error, failed_at FROM closure_rebuild_request'),
        );
        self::assertSame(0, $this->consumed());
    }

    public function testARequestOfADeadRunIsClaimedByTheNextRun(): void
    {
        [$scheduler] = $this->scheduler('2026-10-14 10:00:00');
        $scheduler->requestAnalysis(40);
        $claim = $scheduler->claim('killed-run', 'analysis');

        self::assertSame($claim->requestIds, $scheduler->claim('next-run', 'analysis')->requestIds);
        self::assertSame('next-run', $this->connection()->fetchOne('SELECT claimed_by FROM closure_rebuild_request'));
    }

    public function testARequestIsParkedAfterThreeFailedOrOrphanedClaimsAndCanBeRetried(): void
    {
        [$scheduler] = $this->scheduler('2026-10-14 10:00:00');
        $scheduler->requestAnalysis(40);
        $scheduler->release($scheduler->claim('run-1', 'analysis')->requestIds, new \RuntimeException('first'));
        $scheduler->claim('run-2', 'analysis');
        // run-2 died; run-3 takes the orphan over and fails as well.
        $scheduler->release($scheduler->claim('run-3', 'analysis')->requestIds, new \RuntimeException('third'));

        self::assertTrue($scheduler->claim('run-4', 'analysis')->isEmpty());
        $row = $scheduler->unconsumed(40)[0];
        self::assertSame(3, $row['claim_attempts']);
        self::assertSame('2026-10-14 12:00:00', $row['failed_at']);
        self::assertSame('RuntimeException: third', $row['last_error']);

        self::assertSame(1, $scheduler->retryFailed(40));
        self::assertSame([40], $scheduler->claim('run-5', 'analysis')->hospitalIds);
    }

    public function testAnOrphanThatUsedUpItsAttemptsIsParked(): void
    {
        [$scheduler] = $this->scheduler('2026-10-14 10:00:00');
        $scheduler->requestVolume(40);
        foreach (['run-1', 'run-2', 'run-3'] as $run) {
            $scheduler->claim($run, 'volume');
        }

        self::assertTrue($scheduler->claim('run-4', 'volume')->isEmpty());
        $row = $scheduler->unconsumed(null)[0];
        self::assertNotNull($row['failed_at']);
        self::assertNull($row['claimed_by']);
        self::assertSame('Rebuild run run-3 ended without releasing the request.', $row['last_error']);
    }

    public function testAFailedRebuildLeavesItsRequestsOpenAndASuccessfulOneConsumesThem(): void
    {
        self::bootKernel();
        $this->insertOpenRequest('analysis', 40);
        $this->insertOpenRequest('volume', null);
        $rebuilder = self::getContainer()->get(ClosureVolumeProjectionRebuilder::class);
        $rebuilder->beforePublishHook = static function (): never {
            throw new \RuntimeException('publish failed');
        };
        $rebuild = self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class);

        try {
            $rebuild->consume();
            self::fail('The publish hook should abort the rebuild.');
        } catch (\RuntimeException $exception) {
            self::assertSame('publish failed', $exception->getMessage());
        }
        self::assertSame(0, $this->consumed());
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM closure_rebuild_request WHERE claimed_at IS NOT NULL'));

        $rebuilder->beforePublishHook = null;
        $rebuild->consume();
        self::assertSame(2, $this->consumed());
    }

    public function testTheHourlyCheckSkipsWhileAnotherRebuildHoldsTheLock(): void
    {
        self::bootKernel();
        $this->insertOpenRequest('volume', 40);
        $lock = self::getContainer()->get(LockFactory::class)->createLock('closure-volume-projection', null);
        self::assertTrue($lock->acquire());
        $rebuild = self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class);

        try {
            $rebuild->consume(false);
            self::assertSame(0, (int) $this->connection()->fetchOne('SELECT claim_attempts FROM closure_rebuild_request'));
        } finally {
            $lock->release();
        }

        $rebuild->consume(false);
        self::assertSame(1, $this->consumed());
    }

    /**
     * @return array{ClosureRebuildScheduler, MockClock}
     */
    private function scheduler(string $utcNow): array
    {
        self::bootKernel();
        $clock = new MockClock($utcNow, 'UTC');
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        return [new ClosureRebuildScheduler($this->connection(), $bus, $clock, new NullLogger()), $clock];
    }

    private function insertOpenRequest(string $kind, ?int $hospitalId): void
    {
        $this->connection()->insert('closure_rebuild_request', [
            'kind' => $kind,
            'hospital_id' => $hospitalId,
            'requested_at' => '2026-10-14 10:00:00',
        ]);
    }

    private function consumed(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM closure_rebuild_request WHERE consumed_at IS NOT NULL');
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
