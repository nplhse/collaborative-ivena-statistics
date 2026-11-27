<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Application;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Statistics\Application\DTO\StatisticsFilterInput;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\StatisticsFilterFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class StatisticsFilterFactoryTest extends DatabaseKernelTestCase
{
    use ClockSensitiveTrait;

    public function testInvalidCohortFallsBackToPublicForAnonymousUser(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(StatisticsFilterFactory::class);

        $filter = $factory->createFromInput(
            new StatisticsFilterInput(
                'hospital_cohort',
                '',
                'unknown_cohort',
                '',
                '',
                'last_12_months',
                null,
                null,
                null,
                true,
            ),
            null,
        );

        self::assertSame('public', $filter->scope->value);
        self::assertNull($filter->cohortType);
        self::assertNull($filter->notice);
    }

    public function testMissingCohortFallsBackToPublicForAnonymousUser(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(StatisticsFilterFactory::class);

        $filter = $factory->createFromInput(
            new StatisticsFilterInput(
                'hospital_cohort',
                '',
                '',
                '',
                '',
                'last_12_months',
                null,
                null,
                null,
                true,
            ),
            null,
        );

        self::assertSame('public', $filter->scope->value);
        self::assertNull($filter->cohortType);
        self::assertNull($filter->notice);
    }

    public function testSupportsColonScopedHospitalSyntax(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(StatisticsFilterFactory::class);
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $user]);
        $hospitalId = $hospital->getId();
        self::assertNotNull($hospitalId);

        $filter = $factory->createFromInput(
            new StatisticsFilterInput(
                'hospital:'.$hospitalId,
                '',
                '',
                '',
                '',
                'last_12_months',
                null,
                null,
                null,
                true,
            ),
            $user,
        );

        self::assertSame('hospital', $filter->scope->value);
        self::assertSame($hospitalId, $filter->hospitalId);
    }

    public function testInvalidStateScopeFallsBackToPublic(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(StatisticsFilterFactory::class);

        $filter = $factory->createFromInput(
            new StatisticsFilterInput(
                'state:999999',
                '',
                '',
                '',
                '',
                'last_12_months',
                null,
                null,
                null,
                true,
            ),
            null,
        );

        self::assertSame('public', $filter->scope->value);
        self::assertNull($filter->stateId);
    }

    public function testInvalidDispatchAreaScopeFallsBackToPublic(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(StatisticsFilterFactory::class);

        $filter = $factory->createFromInput(
            new StatisticsFilterInput(
                'dispatch_area:999999',
                '',
                '',
                '',
                '',
                'last_12_months',
                null,
                null,
                null,
                true,
            ),
            null,
        );

        self::assertSame('public', $filter->scope->value);
        self::assertNull($filter->dispatchAreaId);
    }

    public function testQuarterPeriodParsesAndClampsQuarter(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(StatisticsFilterFactory::class);

        $filter = $factory->createFromInput(
            new StatisticsFilterInput(
                'public',
                '',
                '',
                '',
                '',
                'quarter',
                '2021',
                null,
                '9',
                false,
            ),
            null,
        );

        self::assertSame(StatisticsFilterPeriod::Quarter, $filter->period);
        self::assertSame(2021, $filter->referenceYear);
        self::assertSame(4, $filter->referenceQuarter);
        self::assertNull($filter->referenceMonth);
    }

    /**
     * Invalid period query values fall back instead of failing; "now" is 2026-11-11.
     */
    #[DataProvider('provideInvalidPeriodValues')]
    public function testInvalidPeriodValuesFallBack(
        string $period,
        mixed $year,
        mixed $month,
        StatisticsFilterPeriod $expectedPeriod,
        ?int $expectedYear,
        ?int $expectedMonth,
    ): void {
        self::mockTime(new \DateTimeImmutable('2026-11-11 10:00:00'));
        self::bootKernel();

        $filter = self::getContainer()->get(StatisticsFilterFactory::class)->createFromInput(
            new StatisticsFilterInput('public', '', '', '', '', $period, $year, $month, null, false),
            null,
        );

        self::assertSame($expectedPeriod, $filter->period);
        self::assertSame($expectedYear, $filter->referenceYear);
        self::assertSame($expectedMonth, $filter->referenceMonth);
    }

    /**
     * @return iterable<string, array{string, mixed, mixed, StatisticsFilterPeriod, ?int, ?int}>
     */
    public static function provideInvalidPeriodValues(): iterable
    {
        yield 'unknown period' => ['next_decade', '2021', '3', StatisticsFilterPeriod::Last12Months, null, null];
        yield 'month 13 is clamped' => ['month', '2021', '13', StatisticsFilterPeriod::Month, 2021, 12];
        yield 'non-numeric month uses the current month' => ['month', '2021', 'abc', StatisticsFilterPeriod::Month, 2021, 11];
        yield 'non-numeric year uses the current year' => ['year', 'abc', null, StatisticsFilterPeriod::Year, 2026, null];
        yield 'negative year uses the current year' => ['month', '-5', '2', StatisticsFilterPeriod::Month, 2026, 2];
    }
}
