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
use Symfony\Component\HttpFoundation\Request;

final class DashboardControllerPeriodTest extends DashboardControllerTestCase
{
    public function testStatisticsOverviewAcceptsMonthPeriodWithYearAndMonth(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?period=month&year=2024&month=3&scope=public',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-heading-title"]', 'Overview');
    }

    public function testStatisticsOverviewAcceptsAllTimePeriod(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=all_time',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-filter-bar"]');
        $this->assertSelectorTextContains('[data-testid="stats-heading-title"]', 'Overview');
    }

    public function testStatisticsOverviewAcceptsYearPeriodWithYear(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=year&year=2024',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-heading-title"]', 'Overview');
    }

    public function testOverviewShowsPeriodNavigation(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/?scope=public&period=year&year=2021');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-period-navigation"]');
        $this->assertSelectorExists('[data-testid="stats-period-primary"]');
        $this->assertSelectorTextContains('[data-testid="stats-period-secondary"]', '2021');
        $this->assertSelectorTextContains('[data-testid="stats-period-nav-previous"] .page-item-title', '2020');
        $this->assertSelectorTextContains('[data-testid="stats-period-nav-next"] .page-item-title', '2022');
    }

    public function testOverviewQuarterPeriodIsAccepted(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=quarter&year=2021&quarter=2',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-period-secondary"]');
    }

    public function testOverviewYearModeSecondaryListsYearsOnly(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=year&year=2021',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-period-secondary"]');
        $this->assertCount(
            0,
            $crawler->filter('[data-testid="stats-period-secondary"] + .dropdown-menu a[href*="period=month"]'),
        );
    }

    public function testOverviewAllTimeHidesPeriodNavigation(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=all_time',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-period-navigation"]');
    }

    public function testOverviewLast12MonthsHidesPeriodNavigation(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-period-navigation"]');
    }

    public function testOverviewMonthPeriodShowsPreviousAndNextOnly(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=month&year=2021&month=1',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-period-nav-previous"]');
        $this->assertSelectorExists('[data-testid="stats-period-nav-next"]');
        $this->assertSelectorNotExists('[data-testid="stats-period-nav-parent"]');
    }

    public function testOverviewLast12MonthsAppearsInPeriodPrimaryMenu(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=year&year=2021',
        );

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(
            0,
            $crawler->filter('[data-testid="stats-period-primary"] + .dropdown-menu a[href*="period=all"]')->count(),
        );
    }

    /**
     * These scenarios truncate and rebuild projection/MVs; keep them last so earlier
     * period-navigation assertions still see fixture year coverage.
     */
    public function testOverviewRedirectsToLast12MonthsWhenEnoughMonthlyDataExists(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedDefaultPeriodScenario(7);

        $client->request(Request::METHOD_GET, '/statistics/?scope=public');

        $this->assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('period=all', $location);
        self::assertStringContainsString('scope=public', $location);
    }

    public function testOverviewKeepsAllTimeWhenFewerThanSevenMonthsHaveData(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedDefaultPeriodScenario(3);

        $client->request(Request::METHOD_GET, '/statistics/?scope=public');

        $this->assertResponseIsSuccessful();
        self::assertStringNotContainsString('period=all', $client->getRequest()->getUri());
    }

    private function seedDefaultPeriodScenario(int $monthCount): void
    {
        $user = UserFactory::createOne(['username' => 'default-period-fn-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'DefaultPeriodFnState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'DefaultPeriodFnDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'DefaultPeriodFnHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'DefaultPeriodFnSpec']);
        DepartmentFactory::createOne(['name' => 'DefaultPeriodFnDept']);
        AssignmentFactory::createOne(['name' => 'DefaultPeriodFnAssign']);
        IndicationRawFactory::createOne(['name' => 'DefaultPeriodFnRaw', 'code' => 912_371]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'DefaultPeriodFnNorm']);

        $import = ImportFactory::createOne([
            'name' => 'DefaultPeriodFnImport',
            'hospital' => $hospital,
            'createdBy' => $user,
        ]);

        $start = new \DateTimeImmutable('first day of this month')->modify('-11 months')->setTime(0, 0, 0);
        for ($offset = 0; $offset < $monthCount; ++$offset) {
            $createdAt = $start->modify(sprintf('+%d months', $offset))->modify('+10 days');
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
