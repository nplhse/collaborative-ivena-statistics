<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\ClosureAnalytics;

use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
use App\Statistics\ClosureAnalytics\Infrastructure\Projection\ClosureRebuildClaim;
use App\Statistics\ClosureAnalytics\Infrastructure\Projection\ClosureRebuildScheduler;
use App\Statistics\ClosureAnalytics\Infrastructure\Projection\ClosureVolumeProjectionRebuilder;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ClosureRebuildSchedulerTest extends KernelTestCase
{
    use Factories;

    public function testRequestTimesAreStoredAsEuropeBerlinWallClock(): void
    {
        [$scheduler, $clock] = $this->scheduler('2026-10-14 15:58:55');
        $hospital = $this->hospitalWithClosures();
        $scheduler->requestAnalysis($hospital);
        $claim = $scheduler->claim('run-a', 'analysis', [$hospital]);
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
        $first = $this->hospitalWithClosures();
        $second = $this->hospitalWithClosures();
        $scheduler->requestAnalysis($first);
        $scheduler->requestAnalysis($second);

        $claim = $scheduler->claim('run-a', 'analysis', [$first]);
        self::assertSame([$first], $claim->hospitalIds);
        self::assertCount(1, $claim->requestIds);
        self::assertSame(0, $this->consumed());
        self::assertTrue($scheduler->claim('run-a', 'analysis', [$first])->isEmpty(), 'A run does not claim its own request twice.');
        self::assertSame([$second], $scheduler->claim('run-a', 'analysis')->hospitalIds);

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
        $scheduler->requestAnalysis($this->hospitalWithClosures());
        $claim = $scheduler->claim('killed-run', 'analysis');

        self::assertSame($claim->requestIds, $scheduler->claim('next-run', 'analysis')->requestIds);
        self::assertSame('next-run', $this->connection()->fetchOne('SELECT claimed_by FROM closure_rebuild_request'));
    }

    public function testARequestIsParkedAfterThreeFailedOrOrphanedClaimsAndCanBeRetried(): void
    {
        [$scheduler] = $this->scheduler('2026-10-14 10:00:00');
        $hospital = $this->hospitalWithClosures();
        $scheduler->requestAnalysis($hospital);
        $scheduler->release($scheduler->claim('run-1', 'analysis')->requestIds, new \RuntimeException('first'));
        $scheduler->claim('run-2', 'analysis');
        // run-2 died; run-3 takes the orphan over and fails as well.
        $scheduler->release($scheduler->claim('run-3', 'analysis')->requestIds, new \RuntimeException('third'));

        self::assertTrue($scheduler->claim('run-4', 'analysis')->isEmpty());
        $row = $scheduler->unconsumed($hospital)[0];
        self::assertSame(3, $row['claim_attempts']);
        self::assertSame('2026-10-14 12:00:00', $row['failed_at']);
        self::assertSame('RuntimeException: third', $row['last_error']);

        self::assertSame(1, $scheduler->retryFailed($hospital));
        self::assertSame([$hospital], $scheduler->claim('run-5', 'analysis')->hospitalIds);
    }

    public function testAnOrphanThatUsedUpItsAttemptsIsParked(): void
    {
        [$scheduler] = $this->scheduler('2026-10-14 10:00:00');
        $scheduler->requestVolume($this->hospitalWithClosures());
        foreach (['run-1', 'run-2', 'run-3'] as $run) {
            $scheduler->claim($run, 'volume');
        }

        self::assertTrue($scheduler->claim('run-4', 'volume')->isEmpty());
        $row = $scheduler->unconsumed(null)[0];
        self::assertNotNull($row['failed_at']);
        self::assertNull($row['claimed_by']);
        self::assertSame('Rebuild run run-3 ended without releasing the request.', $row['last_error']);
    }

    public function testHospitalsWithoutClosureDataAreNotQueued(): void
    {
        [$scheduler] = $this->scheduler('2026-10-14 10:00:00');
        $withoutClosures = $this->hospital();
        $withSourceRowsOnly = $this->hospital();
        $this->connection()->insert('closure_interval', [
            'hospital_id' => $withSourceRowsOnly,
            'import_id' => ImportFactory::createOne(['hospital' => HospitalFactory::find($withSourceRowsOnly)])->getId(),
            'speciality_id' => SpecialityFactory::createOne()->getId(),
            'department_id' => DepartmentFactory::createOne()->getId(),
            'starts_at' => '2026-06-02 10:00:00',
            'ends_at' => '2026-06-02 11:00:00',
            'care_level' => 'emergency',
            'reason' => 'no_bed_capacity',
            'facility_kind' => 'clinic',
            'closure_unit' => 'unit',
            'source_group_id' => 'grp',
            'source_recorded_at' => '2026-06-02 09:00:00',
            'source_changed_at' => '2026-06-02 09:00:00',
        ]);

        self::assertFalse($scheduler->requestAnalysis($withoutClosures));
        $scheduler->requestVolume($withoutClosures);
        self::assertSame(0, $this->requests());
        self::assertTrue($scheduler->requestAnalysis($withSourceRowsOnly));
        $scheduler->requestVolume(null);
        self::assertSame(2, $this->requests(), 'A request for all hospitals needs no closure check.');
    }

    public function testAnOpenRequestCoversRepeatedAndNarrowerRequests(): void
    {
        [$scheduler] = $this->scheduler('2026-10-14 10:00:00');
        $hospital = $this->hospitalWithClosures();
        $other = $this->hospitalWithClosures();

        self::assertTrue($scheduler->requestAnalysis($hospital));
        self::assertFalse($scheduler->requestAnalysis($hospital));
        $scheduler->requestVolume($hospital);
        self::assertSame(1, $this->requests(), 'An open analysis covers volume.');
        $scheduler->requestVolume($other);
        self::assertTrue($scheduler->requestAnalysis($other), 'An open volume does not cover analysis.');
        self::assertSame(3, $this->requests());

        $scheduler->requestVolume(null);
        $scheduler->requestVolume($hospital);
        self::assertSame(4, $this->requests(), 'An open request for all hospitals covers each one.');
    }

    public function testAnOpenRangedRequestIsWidenedAndAWholeHospitalRequestWins(): void
    {
        [$scheduler] = $this->scheduler('2026-10-14 10:00:00');
        $hospital = $this->hospitalWithClosures();
        $range = fn (): array|false => $this->connection()->fetchAssociative('SELECT range_from, range_to FROM closure_rebuild_request');

        $scheduler->requestVolume($hospital, new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-04-01'));
        $scheduler->requestVolume($hospital, new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-06-01'));
        self::assertSame(1, $this->requests());
        self::assertSame(['range_from' => '2026-03-01 00:00:00', 'range_to' => '2026-06-01 00:00:00'], $range());

        self::assertTrue($scheduler->requestAnalysis($hospital, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-02-01')));
        self::assertSame(2, $this->requests(), 'A volume request does not cover an analysis.');
        $scheduler->requestVolume($hospital);
        self::assertSame(2, $this->requests());
        self::assertSame(
            [null, null],
            array_values($this->connection()->fetchAssociative("SELECT range_from, range_to FROM closure_rebuild_request WHERE kind = 'volume'") ?: []),
            'A request without range widens the oldest covering request to the whole hospital.',
        );
        self::assertSame(
            ['range_from' => '2026-01-01 00:00:00', 'range_to' => '2026-02-01 00:00:00'],
            $this->connection()->fetchAssociative("SELECT range_from, range_to FROM closure_rebuild_request WHERE kind = 'analysis'"),
        );
    }

    public function testAClaimCarriesTheRangesPerHospital(): void
    {
        [$scheduler] = $this->scheduler('2026-10-14 10:00:00');
        $ranged = $this->hospitalWithClosures();
        $whole = $this->hospitalWithClosures();
        $scheduler->requestVolume($ranged, new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-04-01'));
        $scheduler->requestVolume($whole);

        $claim = $scheduler->claim('run-1', 'volume');

        self::assertFalse($claim->all);
        $ranges = $claim->rangesFor($ranged);
        self::assertNotNull($ranges);
        self::assertCount(1, $ranges);
        self::assertSame('2026-03-01 00:00', $ranges[0][0]->format('Y-m-d H:i'));
        self::assertNull($claim->rangesFor($whole));
        self::assertNull($claim->merge(new ClosureRebuildClaim(false, [$ranged], [99]))->rangesFor($ranged), 'Merging with a whole-hospital claim drops the ranges.');
    }

    public function testAClaimedRequestDoesNotCoverANewChange(): void
    {
        [$scheduler] = $this->scheduler('2026-10-14 10:00:00');
        $hospital = $this->hospitalWithClosures();
        $scheduler->requestAnalysis($hospital);
        $scheduler->claim('run-a', 'analysis');

        self::assertTrue($scheduler->requestAnalysis($hospital), 'The running build may have read the data before the change.');
        $failed = $this->hospitalWithClosures();
        $scheduler->requestAnalysis($failed);
        $this->connection()->executeStatement("UPDATE closure_rebuild_request SET failed_at = '2026-10-14 12:00:00' WHERE hospital_id = ?", [$failed]);
        self::assertTrue($scheduler->requestAnalysis($failed), 'A parked request does not swallow new changes.');
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

    public function testAConsoleRebuildTakesTheRequestsItCoversBeforeItsFirstPass(): void
    {
        self::bootKernel();
        $this->insertOpenRequest('volume', null);
        $this->insertOpenRequest('analysis', 40);
        $rebuild = self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class);
        $passes = [];

        $rebuild->rebuild(static function (int $done, int $total, int $pass) use (&$passes): void {
            $passes[$pass] = true;
        });

        self::assertSame([1], array_keys($passes), 'A full rebuild covers every open request without a second pass.');
        self::assertSame(2, $this->consumed());
        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT request_count FROM closure_rebuild_run ORDER BY started_at DESC, id DESC LIMIT 1'));
    }

    public function testAHospitalRebuildTakesOnlyTheRequestsOfItsHospitals(): void
    {
        self::bootKernel();
        $this->insertOpenRequest('volume', 40);
        $this->insertOpenRequest('volume', 41);
        $rebuild = self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class);
        $passes = [];

        $rebuild->rebuild(static function (int $done, int $total, int $pass) use (&$passes): void {
            $passes[$pass] = true;
        }, [40]);

        self::assertSame([1], array_keys($passes));
        self::assertSame([40], array_map(intval(...), $this->connection()->fetchFirstColumn('SELECT hospital_id FROM closure_rebuild_request WHERE consumed_at IS NOT NULL')));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM closure_rebuild_request WHERE hospital_id = 41 AND claimed_at IS NOT NULL'), 'The request for hospital 41 stays open for the worker.');
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

    private function hospital(): int
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();

        return (int) HospitalFactory::createOne([
            'state' => $state,
            'dispatchArea' => DispatchAreaFactory::createOne(['state' => $state]),
            'owner' => $user,
            'createdBy' => $user,
        ])->getId();
    }

    private function hospitalWithClosures(): int
    {
        $hospitalId = $this->hospital();
        $this->connection()->insert('closure_event', [
            'hospital_id' => $hospitalId,
            'event_type' => 'single',
            'grouping_key' => 'scheduler-test-'.$hospitalId,
            'grouping_rule' => 'source_group',
            'starts_at' => '2026-06-02 10:00:00',
            'ends_at' => '2026-06-02 11:00:00',
        ]);

        return $hospitalId;
    }

    private function requests(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM closure_rebuild_request');
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
