<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
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
use App\User\Domain\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * The overview resolves its period and clips its time series with the same
 * clock, so a mocked month or year change yields the expected chart months.
 */
final class DashboardControllerClockTest extends DashboardControllerTestCase
{
    use ClockSensitiveTrait;

    /**
     * @param list<string> $expectedMonths
     */
    #[DataProvider('mockedNowProvider')]
    public function testOverviewTimeSeriesFollowsTheMockedClock(string $now, string $query, array $expectedMonths): void
    {
        self::mockTime(new \DateTimeImmutable($now));
        $client = $this->createClientAsRoleUser();
        $this->seedAllocationsIn2027();

        $crawler = $client->request(Request::METHOD_GET, '/statistics/?'.$query);

        $this->assertResponseIsSuccessful();
        $payloadRaw = (string) $crawler->filter('[data-testid="stats-charts"]')->attr('data-indication-dashboard-charts-payload-value');
        /** @var array{timeSeries: array{labels: list<string>}} $payload */
        $payload = json_decode(html_entity_decode($payloadRaw, ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($expectedMonths, $payload['timeSeries']['labels']);
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function mockedNowProvider(): iterable
    {
        yield 'current year defaults to the mocked year' => [
            '2027-06-15 12:00:00',
            'scope=public&period=year',
            ['2027-01', '2027-02', '2027-03', '2027-04', '2027-05'],
        ];
        yield 'completed year after the year change' => [
            '2028-01-01 00:30:00',
            'scope=public&period=year&year=2027',
            ['2027-01', '2027-02', '2027-03', '2027-04', '2027-05', '2027-06', '2027-07', '2027-08', '2027-09', '2027-10', '2027-11', '2027-12'],
        ];
    }

    private function seedAllocationsIn2027(): void
    {
        $user = UserFactory::createOne(['username' => 'overview-clock-fn-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'OverviewClockFnState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'OverviewClockFnDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'OverviewClockFnHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        SpecialityFactory::createOne(['name' => 'OverviewClockFnSpec']);
        DepartmentFactory::createOne(['name' => 'OverviewClockFnDept']);
        AssignmentFactory::createOne(['name' => 'OverviewClockFnAssign']);
        IndicationRawFactory::createOne(['name' => 'OverviewClockFnRaw', 'code' => 912_372]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'OverviewClockFnNorm']);
        $import = ImportFactory::createOne([
            'name' => 'OverviewClockFnImport',
            'hospital' => $hospital,
            'createdBy' => $user,
        ]);

        foreach (['2027-01-15 10:00:00', '2027-12-15 10:00:00'] as $createdAt) {
            $createdAt = new \DateTimeImmutable($createdAt);
            AllocationFactory::createOne([
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatchArea,
                'gender' => AllocationGender::MALE,
                'urgency' => AllocationUrgency::EMERGENCY,
                'indicationNormalized' => $indicationNormalized,
                'createdAt' => $createdAt,
                'arrivalAt' => $createdAt->modify('+20 minutes'),
            ]);
        }

        $this->rebuildProjectionForImports([(int) $import->getId()]);
        $this->refreshOverviewMaterializedViews();
    }
}
