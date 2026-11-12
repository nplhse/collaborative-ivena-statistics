<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital\MyHospitals;

use App\Allocation\Application\DTO\MyHospitals\DataStatusFindingType;
use App\Allocation\Application\DTO\MyHospitals\HospitalDataStatus;
use App\Allocation\Application\DTO\MyHospitals\ImportIssue;
use App\Allocation\Application\DTO\MyHospitals\ImportIssueKind;
use App\Allocation\Application\DTO\MyHospitals\UploaderContribution;
use App\Allocation\Application\Hospital\MyHospitals\CachedHospitalDataSnapshotReader;
use App\Allocation\Application\Hospital\MyHospitals\DataStatusFindingRules;
use App\Allocation\Application\Hospital\MyHospitals\MonthlyCoverageClassifier;
use App\Allocation\Application\Hospital\MyHospitals\MyHospitalsEntry;
use App\Allocation\Application\Hospital\MyHospitals\MyHospitalsOverviewBuilder;
use App\Allocation\Application\Hospital\MyHospitals\MyHospitalsScope;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class MyHospitalsOverviewBuilderTest extends TestCase
{
    use ClockSensitiveTrait;

    private const int USER_ID = 42;

    public function testPartsFollowThePermissionsPerHospital(): void
    {
        $overview = $this->build([
            $this->entry(1, 'Owner Hospital', [HospitalPermission::View, HospitalPermission::Statistics, HospitalPermission::Import]),
            $this->entry(2, 'View Only Hospital', [HospitalPermission::View]),
        ]);

        [$full, $viewOnly] = $overview->hospitals;

        self::assertSame(300, $full->allocationCount);
        self::assertNotNull($full->imports);
        self::assertSame(2, $full->imports->relevant);
        self::assertSame(1, $full->imports->ownImports);
        self::assertSame('2026-10-01', $full->imports->ownLastUploadAt?->format('Y-m-d'));
        self::assertCount(12, $full->months ?? []);
        self::assertSame(2, $full->fullyCoveredMonths); // September and October 2026
        self::assertSame([DataStatusFindingType::ImportFailed], $this->findingTypes($full));

        self::assertSame(50, $viewOnly->allocationCount);
        self::assertNull($viewOnly->imports);
        self::assertNull($viewOnly->months);
        self::assertNull($viewOnly->fullyCoveredMonths);
        self::assertNull($viewOnly->gaps);
        // The failed import and the gap are hidden; only "not updated" needs no more than VIEW.
        self::assertSame([DataStatusFindingType::NotUpdated], $this->findingTypes($viewOnly));
    }

    public function testAggregateStatesItsBasis(): void
    {
        $overview = $this->build([
            $this->entry(1, 'Owner Hospital', [HospitalPermission::View, HospitalPermission::Statistics, HospitalPermission::Import]),
            $this->entry(2, 'View Only Hospital', [HospitalPermission::View]),
        ]);

        self::assertSame(2, $overview->hospitalCount());
        self::assertSame(350, $overview->allocationCount);
        self::assertSame('2026-03-02', $overview->firstAllocationAt?->format('Y-m-d'));
        self::assertSame('2026-10-30', $overview->lastAllocationAt?->format('Y-m-d'));

        self::assertNotNull($overview->imports);
        self::assertSame(1, $overview->imports->hospitals);
        self::assertSame(2, $overview->imports->relevant);
        self::assertSame(1, $overview->imports->problems);

        self::assertSame(1, $overview->statisticsHospitals);
        self::assertNotNull($overview->months);
        self::assertCount(12, $overview->months);
        $october = $overview->months[10];
        self::assertSame('2026-10', $october->month->format('Y-m'));
        self::assertSame(200, $october->allocations);
        self::assertSame(1, $october->coveredHospitals);
        self::assertFalse($october->inProgress);
        self::assertTrue($overview->months[11]->inProgress);
    }

    public function testAggregateNamesHospitalsWithGaps(): void
    {
        $overview = $this->build([
            $this->entry(1, 'Owner Hospital', [HospitalPermission::View, HospitalPermission::Statistics]),
            $this->entry(3, 'Gap Hospital', [HospitalPermission::View, HospitalPermission::Statistics]),
        ]);

        self::assertNotNull($overview->months);
        $september = $overview->months[9];
        self::assertSame('2026-09', $september->month->format('Y-m'));
        self::assertSame(2, $september->hospitals);
        self::assertSame(1, $september->coveredHospitals);
        self::assertSame(['Gap Hospital'], $september->hospitalsWithGaps);
        self::assertNull($overview->imports);
    }

    /**
     * @param list<MyHospitalsEntry> $entries
     */
    private function build(array $entries): \App\Allocation\Application\DTO\MyHospitals\MyHospitalsOverview
    {
        $clock = self::mockTime(new \DateTimeImmutable('2026-11-12 09:30:00'));
        $snapshots = [
            1 => SnapshotBuilder::snapshot(
                hospitalId: 1,
                volumes: ['2026-09' => 100, '2026-10' => 200],
                periods: [['2026-09-01', '2026-10-31']],
                firstAllocationAt: '2026-09-01 08:00:00',
                lastAllocationAt: '2026-10-30 22:00:00',
                issues: [new ImportIssue(9, ImportIssueKind::Failed, new \DateTimeImmutable('2026-11-05 10:00:00'), 0)],
                lastUploadAt: '2026-11-01 10:00:00',
                relevantImports: 2,
                failedImports: 1,
                uploaders: [
                    self::USER_ID => new UploaderContribution(1, new \DateTimeImmutable('2026-10-01 10:00:00')),
                    7 => new UploaderContribution(1, new \DateTimeImmutable('2026-11-01 10:00:00')),
                ],
            ),
            2 => SnapshotBuilder::snapshot(
                hospitalId: 2,
                volumes: ['2026-03' => 30, '2026-05' => 20],
                periods: [['2026-03-01', '2026-03-31'], ['2026-05-01', '2026-05-31']],
                firstAllocationAt: '2026-03-02 08:00:00',
                lastAllocationAt: '2026-05-31 20:00:00',
                issues: [new ImportIssue(11, ImportIssueKind::Failed, new \DateTimeImmutable('2026-11-01 10:00:00'), 0)],
            ),
            3 => SnapshotBuilder::snapshot(
                hospitalId: 3,
                volumes: ['2026-08' => 10, '2026-10' => 10],
                periods: [['2026-08-01', '2026-08-31'], ['2026-10-01', '2026-10-31']],
                firstAllocationAt: '2026-08-01 08:00:00',
                lastAllocationAt: '2026-10-31 08:00:00',
            ),
        ];

        $builder = new MyHospitalsOverviewBuilder(
            new CachedHospitalDataSnapshotReader(new RecordingSnapshotReader($snapshots), new ArrayAdapter()),
            new MonthlyCoverageClassifier(),
            new DataStatusFindingRules(),
            $clock,
        );

        return $builder->build(new MyHospitalsScope($entries), self::USER_ID);
    }

    /**
     * @param list<HospitalPermission> $permissions
     */
    private function entry(int $id, string $name, array $permissions): MyHospitalsEntry
    {
        $hospital = new Hospital();
        $hospital->setName($name);
        new \ReflectionProperty(Hospital::class, 'id')->setValue($hospital, $id);

        return new MyHospitalsEntry($hospital, false, HospitalPermissionMask::fromPermissions($permissions));
    }

    /**
     * @return list<DataStatusFindingType>
     */
    private function findingTypes(HospitalDataStatus $status): array
    {
        return array_map(static fn (\App\Allocation\Application\DTO\MyHospitals\DataStatusFinding $finding): DataStatusFindingType => $finding->type, $status->findings);
    }
}
