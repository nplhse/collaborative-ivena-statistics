<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Application\Overview;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\Overview\Dto\OverviewSelfBenchmarkFrameViewModel;
use App\Statistics\Application\Overview\OverviewSelfBenchmarkFrameFactory;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * #1071: the overview compares with the previous period of the same length.
 *
 * "Now" is 2026-11-13 10:00. Fixture for one hospital:
 * - 100 allocations on 5 October (inside 1–13 October),
 * - 40 allocations on 20 October (after the 13th, so outside the running-month comparison),
 * - 125 allocations on 5 November.
 *
 * Expected by hand for the running month November:
 * - comparison period 1 October 00:00 – 13 October 10:00 → 100 allocations,
 * - volume +25.0% (125 against 100),
 * - cases per day over 12 days on both sides: 125 / 12 = 10.4 and 100 / 12 = 8.3.
 * For the complete month October (140) the comparison is September (0 allocations): below the minimum of 100
 * cases on either side, so no insights. For all time there is no comparison and no insight.
 */
#[ResetDatabase]
final class OverviewSelfBenchmarkFrameFactoryTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-13 10:00:00'));
    }

    public function testRunningMonthIsComparedWithTheSameDaysOfThePreviousMonth(): void
    {
        [$user, $hospitalId] = $this->createFixture();

        $frame = $this->frame($user, $hospitalId, StatisticsFilterPeriod::Month, 11);

        self::assertTrue($frame->hasComparison());
        self::assertSame('2026-10-01', $frame->comparisonFirstDay()?->format('Y-m-d'));
        self::assertSame('2026-10-13', $frame->comparisonLastDay()?->format('Y-m-d'));

        $casesPerDay = $this->metric($frame, BenchmarkMetricKey::CasesPerDay);
        self::assertSame(10.4, $casesPerDay->primaryValue);
        self::assertSame(8.3, $casesPerDay->comparisonValue);

        self::assertNotSame([], $frame->hospitalInsights);
        self::assertSame('Allocation volume', $frame->hospitalInsights[0]->title);
        self::assertSame('+25.0% allocations over the same days of the previous month.', $frame->hospitalInsights[0]->body);
    }

    public function testCompleteMonthIsComparedWithTheWholePreviousMonth(): void
    {
        [$user, $hospitalId] = $this->createFixture();

        $frame = $this->frame($user, $hospitalId, StatisticsFilterPeriod::Month, 10);

        self::assertSame('2026-09-01', $frame->comparisonFirstDay()?->format('Y-m-d'));
        self::assertSame('2026-09-30', $frame->comparisonLastDay()?->format('Y-m-d'));
        // 140 allocations in October, none in September: below 100 cases in the comparison period.
        self::assertSame([], $frame->hospitalInsights);
    }

    public function testAllTimeHasNoComparisonAndNoInsights(): void
    {
        [$user, $hospitalId] = $this->createFixture();

        $frame = $this->frame($user, $hospitalId, StatisticsFilterPeriod::AllTime, null);

        self::assertFalse($frame->hasComparison());
        self::assertNull($frame->comparisonFirstDay());
        self::assertSame([], $frame->hospitalInsights);
    }

    private function frame(User $user, int $hospitalId, StatisticsFilterPeriod $period, ?int $month): OverviewSelfBenchmarkFrameViewModel
    {
        $filter = new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            $hospitalId,
            null,
            $period,
            null === $month ? null : 2026,
            $month,
        );

        return self::getContainer()->get(OverviewSelfBenchmarkFrameFactory::class)->build(
            Request::create('/statistics/'),
            new StatisticsContext($user, $filter),
            'November 2026',
        );
    }

    private function metric(OverviewSelfBenchmarkFrameViewModel $frame, BenchmarkMetricKey $key): BenchmarkMetric
    {
        foreach ($frame->kpiMetrics as $metric) {
            if ($metric->key === $key) {
                return $metric;
            }
        }

        self::fail(sprintf('Metric %s missing', $key->value));
    }

    /**
     * @return array{User, int}
     */
    private function createFixture(): array
    {
        self::bootKernel();

        $user = UserFactory::new()->asAdmin()->create(['username' => 'overview-cmp-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'OverviewCmpState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'OverviewCmpDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'OverviewCmpHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'OverviewCmpSpec']);
        DepartmentFactory::createOne(['name' => 'OverviewCmpDept']);
        AssignmentFactory::createOne(['name' => 'OverviewCmpAssign']);
        IndicationRawFactory::createOne(['name' => 'OverviewCmpRaw', 'code' => 912_371]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'OverviewCmpNorm']);

        $import = ImportFactory::createOne(['name' => 'OverviewCmpImport', 'hospital' => $hospital, 'createdBy' => $user]);

        foreach (['2026-10-05 10:00:00' => 100, '2026-10-20 10:00:00' => 40, '2026-11-05 10:00:00' => 125] as $createdAt => $count) {
            $at = new \DateTimeImmutable($createdAt);
            AllocationFactory::createMany($count, [
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatchArea,
                'indicationNormalized' => $indication,
                'createdAt' => $at,
                'arrivalAt' => $at->modify('+20 minutes'),
            ]);
        }

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        return [$user, $hospital->getId()];
    }
}
