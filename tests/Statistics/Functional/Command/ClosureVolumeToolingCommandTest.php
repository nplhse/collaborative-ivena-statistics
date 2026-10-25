<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Command;

use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\UI\Console\Command\DeriveImportExportPeriodsCommand;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
use App\Statistics\UI\Console\Command\ClosureRebuildRequestsCommand;
use App\Statistics\UI\Console\Command\ClosureVolumeDiagnosticsCommand;
use App\Statistics\UI\Console\Command\ClosureVolumeSnapshotCommand;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The operational tools around ADR 015: export period derivation, diagnostics, snapshots and rebuild requests.
 */
#[ResetDatabase]
final class ClosureVolumeToolingCommandTest extends KernelTestCase
{
    use Factories;

    #[Test]
    public function exportPeriodsAreShownInADryRunAndWrittenWithApply(): void
    {
        self::bootKernel();
        $seed = $this->seed();
        $tester = new CommandTester(self::getContainer()->get(DeriveImportExportPeriodsCommand::class));

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Would update 2 import export period(s). Run with --apply to write.', $tester->getDisplay());
        self::assertStringContainsString('2026-05-05 00:00 → 2026-06-03 00:00', $tester->getDisplay());
        self::assertNull($this->connection()->fetchOne('SELECT export_starts_at FROM import WHERE id = ?', [$seed['allocationImportId']]));

        $tester->execute(['--apply' => true, '--hospital' => (string) $seed['hospitalId']]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Updated 2 import export period(s).', $tester->getDisplay());
        self::assertStringContainsString('Closure rebuild queued for hospitals: '.$seed['hospitalId'], $tester->getDisplay());
        self::assertSame('2026-05-05 00:00:00', $this->connection()->fetchOne('SELECT export_starts_at FROM import WHERE id = ?', [$seed['allocationImportId']]));
        self::assertSame('derived', $this->connection()->fetchOne('SELECT export_period_source FROM import WHERE id = ?', [$seed['closureImportId']]));

        $tester->execute(['--show-unchanged' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Would update 0 import export period(s).', $tester->getDisplay());
        self::assertStringContainsString('no', $tester->getDisplay());

        $this->connection()->executeStatement("UPDATE import SET export_period_source = 'manual' WHERE id = ?", [$seed['closureImportId']]);
        $this->connection()->executeStatement("UPDATE import SET export_starts_at = '2026-01-01' WHERE id = ?", [$seed['allocationImportId']]);
        $tester->execute(['--apply' => true, '--show-unchanged' => true]);
        self::assertStringContainsString('manual, kept', $tester->getDisplay());
        self::assertStringContainsString('Updated 1 import export period(s).', $tester->getDisplay());
        self::assertStringContainsString('Closure rebuild queued for hospitals: '.$seed['hospitalId'], $tester->getDisplay());

        // Without closure data nothing is queued.
        $this->connection()->executeStatement('DELETE FROM closure_interval');
        $this->connection()->executeStatement('DELETE FROM closure_event');
        $this->connection()->executeStatement("UPDATE import SET export_starts_at = '2026-01-01' WHERE id = ?", [$seed['allocationImportId']]);
        $tester->execute(['--apply' => true]);
        self::assertStringContainsString('No closure rebuild queued (no closure data or already queued) for hospitals: '.$seed['hospitalId'], $tester->getDisplay());
    }

    #[Test]
    public function diagnosticsReportQualityCoverageBoundariesAndProvisionalEvents(): void
    {
        self::bootKernel();
        $seed = $this->seed();
        $tester = new CommandTester(self::getContainer()->get(ClosureVolumeDiagnosticsCommand::class));
        self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class)->rebuild();

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('Reference quality', $display);
        self::assertStringContainsString('no – run app:import:derive-export-periods', $display);
        self::assertStringContainsString('coverage not derived', $display);

        new CommandTester(self::getContainer()->get(DeriveImportExportPeriodsCommand::class))->execute(['--apply' => true]);
        $tester->execute(['--hospital' => (string) $seed['hospitalId'], '--weeks' => '2']);
        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('Provisional events with a symmetric ±2-week window', $display);
        self::assertStringContainsString('2026-05-05 00:00', $display);
        self::assertStringContainsString('Closures at export boundaries', $display);
    }

    #[Test]
    public function snapshotsAreSavedComparedListedAndDropped(): void
    {
        self::bootKernel();
        $this->seed();
        self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class)->rebuild();
        $tester = new CommandTester(self::getContainer()->get(ClosureVolumeSnapshotCommand::class));

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'save', 'name' => 'tooling']));
        self::assertStringContainsString('Snapshot "tooling" stored with', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'list']));
        self::assertStringContainsString('tooling', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'compare', 'name' => 'tooling']));
        self::assertStringContainsString('Identical within tolerance.', $tester->getDisplay());

        $this->connection()->executeStatement('UPDATE closure_volume_hour SET expected_area = COALESCE(expected_area, 0) + 2 WHERE id = (SELECT MIN(id) FROM closure_volume_hour)');
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'compare', 'name' => 'tooling', '--tolerance' => '0.5']));
        self::assertStringContainsString('Differences found.', $tester->getDisplay());
        self::assertStringContainsString('expected_area', $tester->getDisplay());

        self::assertSame(Command::INVALID, $tester->execute(['action' => 'compare', 'name' => 'missing']));
        self::assertStringContainsString('does not exist', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['action' => 'save']));
        self::assertSame(Command::INVALID, $tester->execute(['action' => 'archive', 'name' => 'tooling']));
        self::assertSame(Command::INVALID, $tester->execute(['action' => 'save', 'name' => 'Not Valid']));

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'drop', 'name' => 'tooling']));
        $tester->execute(['action' => 'list']);
        self::assertStringContainsString('No snapshots.', $tester->getDisplay());
    }

    #[Test]
    public function rebuildRequestsAreListedAndFailedOnesRequeued(): void
    {
        self::bootKernel();
        $seed = $this->seed();
        $this->connection()->insert('closure_rebuild_request', [
            'kind' => 'volume',
            'hospital_id' => $seed['hospitalId'],
            'requested_at' => '2026-10-14 15:58:55',
            'range_from' => '2026-05-01 00:00:00',
            'range_to' => '2026-06-01 00:00:00',
            'claimed_at' => '2026-10-14 16:00:00',
            'claimed_by' => 'run-1',
            'claim_attempts' => 1,
        ]);
        $this->connection()->insert('closure_rebuild_request', [
            'kind' => 'analysis',
            'hospital_id' => null,
            'requested_at' => '2026-10-14 15:59:00',
            'claim_attempts' => 3,
            'failed_at' => '2026-10-14 17:00:00',
            'last_error' => 'publish failed',
        ]);
        $tester = new CommandTester(self::getContainer()->get(ClosureRebuildRequestsCommand::class));

        $tester->execute(['--hospital' => (string) $seed['hospitalId']]);
        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('2026-05-01 – 2026-06-01', $display);
        self::assertStringContainsString('whole hospital', $display);
        self::assertStringContainsString('claimed 2026-10-14 16:00:00 by run-1', $display);
        self::assertStringContainsString('failed 2026-10-14 17:00:00', $display);
        self::assertStringContainsString('publish failed', $display);
        self::assertStringContainsString('Re-queue failed requests with --retry-failed', $display);

        $tester->execute(['--retry-failed' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 failed requests re-queued.', $tester->getDisplay());
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM closure_rebuild_request WHERE failed_at IS NOT NULL'));
    }

    /**
     * An allocation import with assignments in May and June and a closure import with one closure in June.
     *
     * @return array{hospitalId: int, allocationImportId: int, closureImportId: int}
     */
    private function seed(): array
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['state' => $state, 'dispatchArea' => $dispatch, 'owner' => $user, 'createdBy' => $user]);
        $speciality = SpecialityFactory::createOne();
        $department = DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne(['code' => random_int(600, 699)]);
        IndicationNormalizedFactory::createOne();
        $allocationImport = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user, 'type' => ImportType::ALLOCATION, 'status' => ImportStatus::COMPLETED]);
        $closureImport = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user, 'type' => ImportType::CLOSURE, 'status' => ImportStatus::COMPLETED]);

        foreach (['2026-05-05', '2026-05-12', '2026-05-19', '2026-05-26', '2026-06-02'] as $day) {
            AllocationFactory::createOne([
                'import' => $allocationImport,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatch,
                'speciality' => $speciality,
                'department' => $department,
                'createdAt' => new \DateTimeImmutable($day.' 10:10:00'),
                'arrivalAt' => new \DateTimeImmutable($day.' 10:30:00'),
                'urgency' => AllocationUrgency::EMERGENCY,
                'requiresResus' => false,
                'requiresCathlab' => false,
            ]);
        }
        $this->connection()->insert('closure_interval', [
            'hospital_id' => $hospital->getId(),
            'import_id' => $closureImport->getId(),
            'speciality_id' => $speciality->getId(),
            'department_id' => $department->getId(),
            'starts_at' => '2026-06-02 10:00:00',
            'ends_at' => '2026-06-02 11:00:00',
            'care_level' => 'emergency',
            'reason' => 'no_bed_capacity',
            'facility_kind' => 'clinic',
            'closure_unit' => 'tooling unit',
            'source_group_id' => null,
            'source_recorded_at' => '2026-06-02 09:00:00',
            'source_changed_at' => '2026-06-02 09:00:00',
        ]);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport((int) $allocationImport->getId());

        return [
            'hospitalId' => (int) $hospital->getId(),
            'allocationImportId' => (int) $allocationImport->getId(),
            'closureImportId' => (int) $closureImport->getId(),
        ];
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
