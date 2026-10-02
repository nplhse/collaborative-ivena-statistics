<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

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
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Shared\Application\DataTable\DataTablePreferenceService;
use App\Statistics\ClosureAnalytics\UI\Twig\ClosureEventTableColumns;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ClosureAnalyticsControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testEmptyDashboardUsesSharedStatisticsControls(): void
    {
        $client = self::createClient();
        $this->loginAsClosureBetaUser($client);
        $client->request(Request::METHOD_GET, '/statistics/closure-analytics?scope=public&period=all_time');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-closure-heading"]', 'Closure analytics');
        $this->assertSelectorExists('[data-testid="stats-closure-kpis"]');
        $this->assertSelectorExists('[data-testid="stats-closure-no-hospital-access"]');
        $this->assertSelectorNotExists('[data-testid="stats-closure-empty"]');
        $this->assertSelectorNotExists('[data-testid="stats-closure-intervals"]');
        $this->assertSelectorExists('[data-testid="stats-closure-tabs"]');
        $this->assertSelectorExists('[data-testid="stats-closure-tab-overview"].active');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-context-scope-group"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-no-hospitals"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-period"]');
        $this->assertSelectorExists('[data-testid="closure-filter-from"]');
        $this->assertSelectorExists('[data-testid="closure-filter-to"]');
        $this->assertSelectorExists('[data-testid="closure-filter-care-levels"]');
        $this->assertSelectorExists('[data-testid="closure-filter-reasons"]');
        $this->assertSelectorExists('[data-testid="closure-filter-event-types"]');
        $this->assertSelectorNotExists('[data-testid="closure-filter-hospitals"]');
        $this->assertSelectorExists('a[href="/statistics/closure-analytics"]');
    }

    public function testClosureAnalyticsRequiresClosureBetaRoleAndIsHiddenFromNavigation(): void
    {
        $client = self::createClient();
        $this->loginAsRoleUser($client);

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics?scope=public&period=all_time');
        $this->assertResponseStatusCodeSame(403);

        $client->request(Request::METHOD_GET, '/statistics/?scope=public&period=all_time');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('a[href="/statistics/closure-analytics"]');
    }

    public function testDispatchAreaScopeIsNotSilentlyApplied(): void
    {
        $client = self::createClient();
        $this->loginAsClosureBetaUser($client);
        $client->request(Request::METHOD_GET, '/statistics/closure-analytics?scope=dispatch_area:99&period=all_time');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-closure-no-hospital-access"]');
        self::assertStringNotContainsString('scope=public', (string) $client->getResponse()->headers->get('location'));
    }

    public function testPeriodStepNavigationIsAvailableOnAllClosurePages(): void
    {
        $client = self::createClient();
        $this->loginAsClosureBetaUser($client);

        foreach ([
            '/statistics/closure-analytics',
            '/statistics/closure-analytics/duration',
            '/statistics/closure-analytics/timeline',
            '/statistics/closure-analytics/events',
        ] as $path) {
            $client->request(
                Request::METHOD_GET,
                $path.'?scope=public&period=month&year=2026&month=5',
            );
            $this->assertResponseIsSuccessful();
            $this->assertSelectorExists('[data-testid="stats-period-navigation"]');
            $this->assertSelectorTextContains('[data-testid="stats-period-nav-previous"]', 'April 2026');
            $this->assertSelectorTextContains('[data-testid="stats-period-nav-next"]', 'June 2026');
            $previousUrl = $client->getCrawler()->filter('[data-testid="stats-period-nav-previous"] a')->attr('href');
            self::assertNotNull($previousUrl);
            self::assertStringStartsWith($path.'?', $previousUrl);
            self::assertStringContainsString('period=month', $previousUrl);
            self::assertStringContainsString('month=4', $previousUrl);
        }
    }

    public function testDetailsEndpointRendersTurboFrame(): void
    {
        $client = self::createClient();
        $this->loginAsClosureBetaUser($client);
        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/details?scope=public&period=all_time');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('turbo-frame#stats-closure-details');
        $this->assertSelectorExists('[data-testid="stats-closure-intervals"]');
        $this->assertSelectorNotExists('[data-testid="stats-closure-breakdowns"]');
    }

    public function testEventTablePaginatesAndPreservesAnalysisState(): void
    {
        $client = self::createClient();
        $user = $this->loginAsClosureBetaUser($client);
        [$hospitalId, , $departmentId, $specialityId] = $this->seedGroupedClosures($user);
        $connection = self::getContainer()->get(Connection::class);
        $importId = (int) $connection->fetchOne(
            'SELECT MIN(id) FROM import WHERE hospital_id = :hospital_id',
            ['hospital_id' => $hospitalId],
        );

        for ($day = 1; $day <= 30; ++$day) {
            $date = sprintf('2026-01-%02d', $day);
            $connection->insert('closure_interval', [
                'hospital_id' => $hospitalId,
                'import_id' => $importId,
                'speciality_id' => $specialityId,
                'department_id' => $departmentId,
                'starts_at' => $date.' 10:00:00',
                'ends_at' => $date.' 11:00:00',
                'care_level' => 'emergency',
                'reason' => 'technical_fault',
                'facility_kind' => 'clinic',
                'closure_unit' => 'Functional unit',
                'source_group_id' => null,
                'source_recorded_at' => $date.' 09:00:00',
                'source_changed_at' => $date.' 09:00:00',
            ]);
        }

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/details?scope=public&period=year&year=2026&sortBy=startsAt&orderBy=asc&limit=25&page=2&closureReasons[]=technical_fault',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(5, '[data-testid="stats-closure-event-row"]');
        $this->assertSelectorTextContains('#result-count', 'Showing 26-30 of 30 results.');
        $this->assertSelectorTextNotContains('[data-testid="stats-closure-intervals"]', 'Ungrouped individual closure');
        $this->assertSelectorTextContains('[data-testid="stats-closure-intervals"]', 'Individual closure');
        $this->assertSelectorExists('[data-testid="stats-closure-intervals"] a.badge.bg-purple-lt.text-purple-lt-fg');
        $previousUrl = $client->getCrawler()->filter('.pagination a')->first()->attr('href');
        self::assertNotNull($previousUrl);
        self::assertStringContainsString('period=year', $previousUrl);
        self::assertStringNotContainsString('scope=public', $previousUrl);
        self::assertStringContainsString('year=2026', $previousUrl);
        self::assertStringContainsString('sortBy=startsAt', $previousUrl);
        self::assertStringContainsString('orderBy=asc', $previousUrl);
        self::assertStringContainsString('closureReasons', $previousUrl);
    }

    public function testEventTableRestoresPerUserPreferencesAndAllowsUrlOverrides(): void
    {
        $client = self::createClient();
        $firstUser = $this->loginAsClosureBetaUser($client);
        $this->seedGroupedClosures($firstUser);
        $definition = self::getContainer()->get(ClosureEventTableColumns::class);
        self::getContainer()->get(DataTablePreferenceService::class)->save(
            $firstUser,
            ClosureEventTableColumns::PREFERENCE_KEY,
            [
                'visibleColumns' => ['reasons'],
                'columnOrder' => ['actualMinutes', 'reasons', 'startsAt', 'event'],
                'pageSize' => 50,
            ],
        );

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/details?scope=public&period=all_time');
        $this->assertResponseIsSuccessful();
        $headers = $client->getCrawler()->filter('[data-testid="stats-closure-intervals"] th')->each(
            static fn ($node): string => trim($node->text()),
        );
        self::assertSame(['Duration', 'Reason', 'Start', 'Event'], array_slice($headers, 0, 4));
        $this->assertSelectorTextContains('[data-testid="stats-closure-intervals"]', '50 records');

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/details?scope=public&period=all_time&columns=hospital&columnOrder=hospital%2CstartsAt%2Cevent%2CactualMinutes&limit=100',
        );
        $this->assertResponseIsSuccessful();
        $overrideHeaders = $client->getCrawler()->filter('[data-testid="stats-closure-intervals"] th')->each(
            static fn ($node): string => trim($node->text()),
        );
        self::assertSame(['Hospital', 'Start', 'Event', 'Duration'], array_slice($overrideHeaders, 0, 4));
        $this->assertSelectorTextContains('[data-testid="stats-closure-intervals"]', '100 records');

        $secondUser = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::CLOSURE_BETA],
        ]);
        $client->loginUser($secondUser);
        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/details?scope=public&period=all_time');
        $this->assertResponseIsSuccessful();
        $secondHeaders = $client->getCrawler()->filter('[data-testid="stats-closure-intervals"] th')->each(
            static fn ($node): string => trim($node->text()),
        );
        self::assertSame(['Start', 'End', 'Hospital', 'Event'], array_slice($secondHeaders, 0, 4));
        $this->assertSelectorTextContains('[data-testid="stats-closure-intervals"]', '25 records');
        self::assertSame(
            $definition->preferenceSchema()->defaults()->toArray(),
            self::getContainer()->get(DataTablePreferenceService::class)
                ->resolve($secondUser, $definition->preferenceSchema())
                ->toArray(),
        );
    }

    public function testDashboardGroupsEventsAndOffersTimelineDrilldown(): void
    {
        $client = self::createClient();
        $user = $this->loginAsClosureBetaUser($client);
        [$hospitalId, $intervalId, $departmentId, $specialityId] = $this->seedGroupedClosures($user);

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics?scope=public&period=all_time');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-closure-kpis"]', 'Actual closure time');
        $this->assertSelectorTextContains('[data-testid="stats-closure-kpis"]', 'Sum of individual durations');
        $this->assertSelectorTextContains('[data-testid="stats-closure-kpis"]', 'Concurrent closure groups');
        $this->assertSelectorCount(3, '[data-testid="stats-closure-kpis"] > div');
        $this->assertSelectorExists('[data-testid="stats-closure-concurrency"] .progress-bar:first-child.bg-primary');
        $this->assertSelectorTextContains('[data-testid="stats-closure-concurrency"] .d-flex > div:first-child', 'Multiple groups');
        $this->assertSelectorTextContains('[data-testid="stats-closure-concurrency"] .d-flex > div:last-child', 'Exactly one group');
        $this->assertSelectorNotExists('[data-testid="stats-closure-coverage"]');
        $this->assertSelectorTextNotContains('[data-testid="stats-closure-kpis"]', 'Intervals');
        $this->assertSelectorNotExists('[data-testid="stats-closure-duration-load"]');
        $this->assertSelectorExists('[data-testid="stats-closure-tab-duration"]');
        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/duration?scope=public&period=all_time');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-closure-tab-duration"].active');
        $this->assertSelectorTextContains('[data-testid="stats-closure-tab-duration"]', 'Duration and time burden');
        $this->assertSelectorTextContains('[data-testid="stats-closure-duration-median"]', 'Median duration');
        $this->assertSelectorTextContains('[data-testid="stats-closure-duration-median"]', '2 h');
        $this->assertSelectorTextNotContains('[data-testid="stats-closure-duration-median"]', 'Typical duration of an event');
        $this->assertSelectorNotExists('[data-testid="stats-closure-duration-clipping"]');
        $this->assertSelectorTextContains('[data-testid="stats-closure-duration-shares"]', 'No department closed');
        $this->assertSelectorTextNotContains('[data-testid="stats-closure-duration-shares"]', 'At least one department closed');
        $this->assertSelectorTextContains('[data-testid="stats-closure-duration-specialities"]', 'Functional Closure Speciality A');
        $this->assertSelectorTextNotContains('[data-testid="stats-closure-duration-specialities"]', 'Functional Closure Speciality A (1)');
        $this->assertSelectorTextContains('[data-testid="stats-closure-duration-specialities"]', 'Specialities by number of closures');
        $this->assertSelectorTextContains('[data-testid="stats-closure-duration-reasons"]', 'No bed capacity');
        $this->assertSelectorTextNotContains('[data-testid="stats-closure-duration-reasons"]', 'No bed capacity (1)');
        $this->assertSelectorTextNotContains('[data-testid="stats-closure-duration-reasons"]', 'Reasons by number of closures');
        $this->assertSelectorTextNotContains('[data-testid="stats-closure-duration-reasons"]', 'must not be added together');
        $this->assertSelectorTextNotContains('[data-testid="stats-closure-duration-specialities"]', 'individual values instead of a box');
        $this->assertSelectorTextContains('[data-testid="stats-closure-duration-pauses"]', 'Not computable');
        $this->assertSelectorTextContains('[data-testid="stats-closure-duration-pauses"]', 'Per 24 h without a closure');
        $this->assertSelectorTextContains('[data-testid="stats-closure-duration-pauses"]', 'Per 24 h with at least one closure');
        $client->request(Request::METHOD_GET, '/statistics/closure-analytics?scope=public&period=all_time');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.col-xl-8 [data-closure-analytics-charts-target="timeSeriesChart"]');
        $this->assertSelectorExists('.col-xl-8 [data-closure-analytics-charts-target="heatmapChart"]');
        $this->assertSelectorTextNotContains('body', 'not a percentage');
        $this->assertSelectorExists('[data-closure-analytics-charts-target="timeSeriesChart"]');
        $this->assertSelectorExists('[data-closure-analytics-charts-target="heatmapChart"]');
        $this->assertSelectorTextContains('[data-testid="stats-closure-breakdown-event_type"]', 'Event types');
        $this->assertSelectorTextContains('[data-testid="stats-closure-breakdown-event_type"]', 'Group');
        $this->assertSelectorTextContains('[data-testid="stats-closure-breakdown-event_type"]', '100,0%');
        $this->assertSelectorTextContains('[data-testid="stats-closure-breakdown-event_type"]', '2 events · 4 h');
        $this->assertSelectorTextContains('[data-testid="stats-closure-breakdown-event_type"]', 'Cluster');
        $this->assertSelectorTextContains('[data-testid="stats-closure-breakdown-event_type"]', 'Individual closure');
        $this->assertSelectorTextContains('[data-testid="stats-closure-breakdown-event_type"]', '0 events');
        $this->assertSelectorTextContains('[data-testid="stats-closure-breakdown-reason"]', 'No bed capacity');
        $this->assertSelectorTextContains('[data-testid="stats-closure-breakdown-reason"]', '100,0%');
        $this->assertSelectorTextContains('[data-testid="stats-closure-breakdown-reason"]', '4 closures · 4 h');
        $this->assertSelectorTextContains('[data-testid="stats-closure-breakdown-care_level"]', 'SK1');
        $this->assertSelectorTextContains('[data-testid="stats-closure-breakdown-care_level"]', '25,0%');
        $this->assertSelectorTextContains('[data-testid="stats-closure-breakdown-care_level"]', '75,0%');
        $this->assertSelectorTextContains('[data-testid="stats-closure-breakdown-care_level"]', '1 closure · 2 h');
        $this->assertSelectorNotExists('[data-testid^="stats-closure-breakdown-"] .card-footer');
        $this->assertSelectorTextContains('[data-testid="stats-closure-breakdowns"]', 'Functional Closure Hospital');
        $this->assertSelectorTextContains('[data-testid="stats-closure-breakdowns"]', 'Functional Closure Department A');
        $this->assertSelectorExists('[data-testid="stats-closure-units"] table');
        $this->assertSelectorTextContains('[data-testid="stats-closure-units"]', 'Hospital');
        $this->assertSelectorTextContains('[data-testid="stats-closure-units"]', 'Name');
        $this->assertSelectorTextContains('[data-testid="stats-closure-units"]', 'Share');
        $this->assertSelectorTextContains('[data-testid="stats-closure-units"]', 'Number of events');
        $this->assertSelectorTextContains('[data-testid="stats-closure-units"]', 'Duration');
        $this->assertSelectorTextContains('[data-testid="stats-closure-units"]', '25 records');
        $this->assertSelectorTextContains('[data-testid="stats-closure-unit-name"]', 'Functional unit');
        $this->assertSelectorTextNotContains('[data-testid="stats-closure-unit-name"]', 'Functional Closure Hospital');
        $this->assertSelectorExists('[data-testid="stats-closure-units"] a[href*="unitsSort=hospital"]');
        $this->assertSelectorExists('[data-testid="stats-closure-units"] .card-footer');
        $this->assertSelectorExists('[data-testid="stats-closure-units"] [data-testid="data-table-columns"]');
        $this->assertSelectorExists('[data-testid="stats-closure-units"] [data-testid="data-table-sort"]');
        $this->assertSelectorExists('[data-testid="stats-closure-units"] form[data-controller="data-table-columns"]');
        $this->assertSelectorExists('[data-testid="stats-closure-units"] [data-testid="data-table-sort-form"][method="post"]');
        $this->assertSelectorExists('[data-testid="stats-closure-units"] [data-testid="data-table-sort-form"] select[name="sortBy"]');
        $this->assertSelectorExists('[data-testid="stats-closure-units"] [data-testid="data-table-sort-form"] select[name="orderBy"]');
        $this->assertSelectorExists('[data-testid="stats-closure-units"] [data-testid="data-table-sort-form"] select[name="pageSize"]');
        $this->assertSelectorExists('[data-testid="stats-closure-units"] th[aria-sort="descending"]');
        $this->assertSelectorExists('[data-testid="closure-filter-units"]');
        $this->assertSelectorNotExists('[data-testid="closure-filter-hospitals"]');
        $this->assertSelectorExists('[data-testid="stats-closure-unit-timeline-link"]');
        $this->assertSelectorNotExists('turbo-frame#stats-closure-timeline');
        $this->assertSelectorNotExists('turbo-frame#stats-closure-details');
        $this->assertSelectorExists('[data-testid="stats-closure-tab-overview"].active');
        $this->assertSelectorExists('[data-testid="statistics-filters-drawer-trigger"]');
        self::assertStringContainsString('closedHours', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('closedShares', (string) $client->getResponse()->getContent());

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics?scope=public&period=all');
        $this->assertResponseIsSuccessful();
        $rollingChart = (string) $client->getResponse()->getContent();
        $currentMonth = new \DateTimeImmutable('first day of this month');
        self::assertStringContainsString($currentMonth->modify('-11 months')->format('Y-m'), $rollingChart);
        self::assertStringContainsString($currentMonth->format('Y-m'), $rollingChart);

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics?scope=public&period=all&unitsColumns=name&unitsColumnOrder=name',
        );
        $this->assertResponseIsSuccessful();
        $unitHeaders = $client->getCrawler()->filter('[data-testid="stats-closure-units"] th')->each(
            static fn ($node): string => trim($node->text()),
        );
        self::assertSame(['Name'], $unitHeaders);

        $hospitalScopeQuery = sprintf(
            'scope=hospital&hospital=%d&period=all_time',
            $hospitalId,
        );
        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics?'.$hospitalScopeQuery,
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="closure-filter-units"]');
        $this->assertSelectorNotExists('[data-testid="closure-filter-hospitals"]');
        $this->assertSelectorExists('[data-testid="closure-filter-units"] option[value="Functional unit"]');
        $this->assertSelectorExists('[data-testid="stats-closure-unit-timeline-link"]');
        $unitTimelineUrl = $client->getCrawler()->filter('[data-testid="stats-closure-unit-timeline-link"]')->attr('href');
        self::assertNotNull($unitTimelineUrl);
        self::assertStringStartsWith('/statistics/closure-analytics/timeline?', $unitTimelineUrl);
        parse_str((string) parse_url($unitTimelineUrl, PHP_URL_QUERY), $unitTimelineQuery);
        self::assertSame(['Functional unit'], $unitTimelineQuery['closureUnits'] ?? null);
        self::assertSame('hospital', $unitTimelineQuery['scope'] ?? null);
        self::assertSame((string) $hospitalId, $unitTimelineQuery['hospital'] ?? null);

        $client->request(Request::METHOD_GET, $unitTimelineUrl);
        $this->assertResponseIsSuccessful();
        $timelineSource = $client->getCrawler()->filter('turbo-frame#stats-closure-timeline')->attr('src');
        self::assertNotNull($timelineSource);
        self::assertStringContainsString('closureUnits', $timelineSource);

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics?'.$hospitalScopeQuery.'&closureUnits[]=Functional%20unit',
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="closure-filter-units"] option[value="Functional unit"][selected]');
        $this->assertSelectorTextContains('[data-testid="statistics-filters-active"]', 'Functional unit');

        $secondState = StateFactory::createOne();
        $secondDispatch = DispatchAreaFactory::createOne(['state' => $secondState]);
        $secondHospital = HospitalFactory::createOne([
            'name' => 'Functional Closure Hospital B',
            'state' => $secondState,
            'dispatchArea' => $secondDispatch,
            'owner' => $user,
            'createdBy' => $user,
        ]);
        $secondDepartment = DepartmentFactory::createOne(['name' => 'Functional Closure Hospital B Department']);
        $secondImport = ImportFactory::createOne(['hospital' => $secondHospital, 'createdBy' => $user]);
        self::getContainer()->get(Connection::class)->insert('closure_interval', [
            'hospital_id' => $secondHospital->getId(),
            'import_id' => $secondImport->getId(),
            'speciality_id' => $specialityId,
            'department_id' => $secondDepartment->getId(),
            'starts_at' => '2026-02-15 10:00:00',
            'ends_at' => '2026-02-15 11:00:00',
            'care_level' => 'inpatient',
            'reason' => 'no_bed_capacity',
            'facility_kind' => 'clinic',
            'closure_unit' => 'Second unit',
            'source_group_id' => 'second-hospital-group',
            'source_recorded_at' => '2026-05-01 09:00:00',
            'source_changed_at' => '2026-05-01 09:00:00',
        ]);

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics?scope=my_hospitals&period=all_time',
        );
        $this->assertResponseIsSuccessful();
        $myHospitalsUnitValue = $hospitalId.':Functional unit';
        $this->assertSelectorExists(sprintf(
            '[data-testid="closure-filter-units"] option[value="%s"]',
            $myHospitalsUnitValue,
        ));
        $myHospitalsTimelineUrl = $client->getCrawler()->filter('[data-testid="stats-closure-unit-timeline-link"]')->attr('href');
        self::assertNotNull($myHospitalsTimelineUrl);
        parse_str((string) parse_url($myHospitalsTimelineUrl, PHP_URL_QUERY), $myHospitalsTimelineQuery);
        self::assertSame([$myHospitalsUnitValue], $myHospitalsTimelineQuery['closureUnits'] ?? null);
        self::assertSame('my_hospitals', $myHospitalsTimelineQuery['scope'] ?? null);

        $filterQuery = sprintf(
            'closureDepartments[]=%d&closureSpecialities[]=%d&closureCareLevels[]=emergency&closureReasons[]=no_bed_capacity',
            $departmentId,
            $specialityId,
        );
        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics?scope=public&period=all_time&'.$filterQuery,
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(sprintf('[data-testid="closure-filter-departments"] option[value="%d"][selected]', $departmentId));
        $this->assertSelectorExists(sprintf('[data-testid="closure-filter-specialities"] option[value="%d"][selected]', $specialityId));
        $this->assertSelectorExists('[data-testid="closure-filter-care-levels"] option[value="emergency"][selected]');
        $this->assertSelectorExists('[data-testid="closure-filter-reasons"] option[value="no_bed_capacity"][selected]');
        self::assertSame('', (string) $client->getCrawler()->filter('[data-testid="closure-filter-from"]')->attr('value'));
        self::assertSame('', (string) $client->getCrawler()->filter('[data-testid="closure-filter-to"]')->attr('value'));
        $this->assertSelectorExists('[data-testid="closure-filter-hospitals"]');
        $this->assertSelectorExists(sprintf('[data-testid="closure-filter-hospitals"] option[value="%d"]', $hospitalId));
        $this->assertSelectorExists(sprintf('[data-testid="closure-filter-hospitals"] option[value="%d"]', $secondHospital->getId()));
        $this->assertSelectorTextContains('[data-testid="statistics-filters-active"]', 'Functional Closure Department A');
        $this->assertSelectorTextContains('[data-testid="statistics-filters-active"]', 'Functional Closure Speciality A');
        $this->assertSelectorTextContains('[data-testid="statistics-filters-active"]', 'SK1');
        $this->assertSelectorTextContains('[data-testid="statistics-filters-active"]', 'No bed capacity');
        $this->assertSelectorTextSame('[data-testid="statistics-filters-drawer-trigger"] .badge', '4');
        $resetUrl = $client->getCrawler()->filter('[data-testid="statistics-filters-clear"]')->attr('href');
        self::assertNotNull($resetUrl);
        self::assertStringNotContainsString('scope=public', $resetUrl);
        self::assertStringContainsString('period=all_time', $resetUrl);
        self::assertStringNotContainsString('closureDepartments', $resetUrl);
        self::assertStringNotContainsString('closureSpecialities', $resetUrl);
        self::assertStringNotContainsString('closureCareLevels', $resetUrl);
        self::assertStringNotContainsString('closureReasons', $resetUrl);
        self::assertStringNotContainsString('closureUnits', $resetUrl);
        self::assertStringNotContainsString('closureFrom', $resetUrl);
        self::assertStringNotContainsString('closureTo', $resetUrl);
        self::assertStringNotContainsString('closureEventTypes', $resetUrl);
        self::assertStringNotContainsString('closureHospitals', $resetUrl);
        $this->assertSelectorTextNotContains('[data-testid="statistics-filters-active"]', 'Functional Closure Hospital B');
        $timelineTabUrl = $client->getCrawler()->filter('[data-testid="stats-closure-tab-timeline"]')->attr('href');
        self::assertNotNull($timelineTabUrl);
        self::assertStringContainsString('closureDepartments', $timelineTabUrl);
        self::assertStringContainsString('closureSpecialities', $timelineTabUrl);
        self::assertStringContainsString('closureCareLevels', $timelineTabUrl);
        self::assertStringContainsString('closureReasons', $timelineTabUrl);

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/timeline?scope=public&period=all_time&'.$filterQuery,
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-closure-tab-timeline"].active');
        $this->assertSelectorExists('[data-testid="stats-closure-timeline-page"]');
        $timelineSource = $client->getCrawler()->filter('turbo-frame#stats-closure-timeline')->attr('src');
        self::assertNotNull($timelineSource);
        self::assertStringContainsString('/statistics/closure-analytics/timeline/frame', $timelineSource);
        self::assertStringContainsString('closureDepartments', $timelineSource);
        self::assertStringContainsString('closureSpecialities', $timelineSource);
        self::assertStringContainsString('closureCareLevels', $timelineSource);
        self::assertStringContainsString('closureReasons', $timelineSource);

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/duration?scope=public&period=all_time&'.$filterQuery,
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-closure-tab-duration"].active');
        $this->assertSelectorTextContains('[data-testid="stats-closure-duration-median"]', 'Median duration');
        $this->assertSelectorTextContains('[data-testid="stats-closure-duration-pauses"]', 'Median gap without a closure');
        $this->assertSelectorExists('[data-testid="stats-closure-duration-pauses"] .datagrid');

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/events?scope=public&period=all_time&'.$filterQuery,
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-closure-tab-events"].active');
        $this->assertSelectorExists('[data-testid="stats-closure-events-page"]');
        $detailsSource = $client->getCrawler()->filter('turbo-frame#stats-closure-details')->attr('src');
        self::assertNotNull($detailsSource);
        self::assertStringContainsString('closureDepartments', $detailsSource);
        self::assertStringContainsString('closureSpecialities', $detailsSource);
        self::assertStringContainsString('closureCareLevels', $detailsSource);
        self::assertStringContainsString('closureReasons', $detailsSource);

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics?scope=public&period=all_time&'.$filterQuery.'&closureFrom=2026-03-01&closureTo=2026-03-15&closureEventTypes[]=group',
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="closure-filter-from"][value="2026-03-01"]');
        $this->assertSelectorExists('[data-testid="closure-filter-to"][value="2026-03-15"]');
        $this->assertSelectorExists('[data-testid="closure-filter-event-types"] option[value="group"][selected]');
        $this->assertSelectorExists('[data-testid="closure-filter-event-types"] option[value="cluster"]');
        $this->assertSelectorExists('[data-testid="closure-filter-event-types"] option[value="single"]');
        $this->assertSelectorTextContains('[data-testid="statistics-filters-active"]', '2026-03-01 – 2026-03-15');
        $this->assertSelectorTextContains('[data-testid="statistics-filters-active"]', 'Group');
        $this->assertSelectorTextSame('[data-testid="statistics-filters-drawer-trigger"] .badge', '6');
        $extendedResetUrl = $client->getCrawler()->filter('[data-testid="statistics-filters-clear"]')->attr('href');
        self::assertNotNull($extendedResetUrl);
        self::assertStringNotContainsString('closureFrom', $extendedResetUrl);
        self::assertStringNotContainsString('closureEventTypes', $extendedResetUrl);

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics?scope=public&period=all_time&'.$filterQuery.'&closureHospitals[]='.$hospitalId,
        );
        $this->assertResponseIsSuccessful();
        parse_str((string) parse_url((string) $client->getRequest()->getUri(), PHP_URL_QUERY), $narrowedHospitalQuery);
        self::assertSame('hospital', $narrowedHospitalQuery['scope'] ?? null);
        self::assertSame((string) $hospitalId, $narrowedHospitalQuery['hospital'] ?? null);
        self::assertArrayNotHasKey('closureHospitals', $narrowedHospitalQuery);
        $this->assertSelectorExists(sprintf(
            '[data-testid="stats-analysis-context-hospitals"] option[value="hospital:%d"][selected]',
            $hospitalId,
        ));
        $this->assertSelectorNotExists(sprintf(
            '[data-testid="stats-analysis-context-hospitals"] option[value="hospital:%d"][selected]',
            $secondHospital->getId(),
        ));
        $this->assertSelectorNotExists('[data-testid="closure-filter-hospitals"]');
        $this->assertSelectorTextContains('[data-testid="stats-closure-breakdowns"]', 'Functional Closure Hospital');
        $this->assertSelectorTextNotContains('[data-testid="stats-closure-breakdowns"]', 'Functional Closure Hospital B');
        $this->assertSelectorTextSame('[data-testid="statistics-filters-drawer-trigger"] .badge', '4');
        $hospitalResetUrl = $client->getCrawler()->filter('[data-testid="statistics-filters-clear"]')->attr('href');
        self::assertNotNull($hospitalResetUrl);
        self::assertStringNotContainsString('closureHospitals', $hospitalResetUrl);

        foreach ([
            ['month', '2026&month=5', '05/2026'],
            ['quarter', '2026&quarter=2', 'Q2 2026'],
            ['year', '2026', '2026'],
        ] as [$period, $periodQuery, $expectedLabel]) {
            $client->request(Request::METHOD_GET, sprintf(
                '/statistics/closure-analytics/timeline/frame?scope=public&period=%s&year=%s',
                $period,
                $periodQuery,
            ));
            $this->assertResponseIsSuccessful();
            $this->assertSelectorCount(1, '[data-testid="closure-timeline-breadcrumb"] .breadcrumb-item');
            $this->assertSelectorTextSame(
                '[data-testid="closure-timeline-breadcrumb"] .breadcrumb-item.active',
                $expectedLabel,
            );
        }
        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/timeline/frame?scope=public&period=month&year=2026&month=5&timeline_grain=year',
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextSame(
            '[data-testid="closure-timeline-breadcrumb"] .breadcrumb-item.active',
            '05/2026',
        );

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/details?scope=public&period=all_time&'.$filterQuery,
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-closure-intervals"]', 'Group');
        $this->assertSelectorExists('[data-testid="stats-closure-intervals"] a.badge.bg-blue-lt.text-blue');
        $this->assertSelectorTextNotContains('[data-testid="stats-closure-intervals"]', 'functional-group');
        $this->assertSelectorExists('[data-testid="stats-closure-intervals"] a.badge[href*="/statistics/closure-analytics/events/"]');
        $this->assertSelectorTextSame('[data-testid="stats-closure-intervals"] tbody tr td:nth-child(7)', '1');
        $this->assertSelectorCount(2, '[data-testid="stats-closure-table-view"] .btn');
        $this->assertSelectorTextContains('[data-testid="stats-closure-table-view"]', 'Cluster');
        $this->assertSelectorTextContains('[data-testid="stats-closure-table-view"]', 'Single');
        $this->assertSelectorExists('[data-testid="stats-closure-table-view"] a[title="Events grouped in clusters"]');
        $this->assertSelectorExists('[data-testid="stats-closure-table-view"] a[title="Show events individually"]');
        $this->assertSelectorExists('[data-testid="stats-closure-table-view"] .btn-outline-secondary.active');
        $this->assertSelectorExists('[data-testid="data-table-configure"].btn-group-sm');
        $this->assertSelectorExists('[data-testid="data-table-columns"].btn-outline-secondary');
        $this->assertSelectorTextContains('[data-testid="data-table-sort"]', 'Sort');
        $this->assertSelectorTextContains('[data-testid="data-table-columns"]', 'Columns');
        $this->assertSelectorExists('[data-testid="data-table-sort-form"]');
        $this->assertSelectorExists('[data-testid="data-table-sort-form"] select[name="sortBy"]');
        $this->assertSelectorExists('[data-testid="data-table-sort-form"] select[name="orderBy"]');
        $this->assertSelectorExists('[data-testid="data-table-sort-form"] select[name="limit"]');
        $this->assertSelectorNotExists('[data-testid="data-table-reset"]');
        $this->assertSelectorExists('[data-testid="stats-closure-intervals"] form[data-controller="data-table-columns"]');
        $this->assertSelectorExists('[data-testid="stats-closure-intervals"] th[aria-sort="descending"]');
        $this->assertSelectorExists('[data-testid="closure-event-facet-departments"] .dropdown');
        $this->assertSelectorTextContains('[data-testid="closure-event-facet-departments"]', 'Functional Closure Department A');
        $this->assertSelectorExists('[data-testid="closure-event-facet-departments"] .bg-red-lt');
        $this->assertSelectorTextContains('[data-testid="closure-event-facet-departments"]', 'Emergency Care');
        $sortUrl = $client->getCrawler()->filter('[data-testid="stats-closure-intervals"] th[aria-sort="descending"] a')->attr('href');
        self::assertNotNull($sortUrl);
        self::assertStringContainsString('sortBy=startsAt', $sortUrl);
        self::assertStringContainsString('orderBy=asc', $sortUrl);
        self::assertStringContainsString('closureDepartments', $sortUrl);
        self::assertStringContainsString('closureReasons', $sortUrl);
        self::assertStringNotContainsString('scope=public', $sortUrl);
        self::assertStringContainsString('period=all_time', $sortUrl);
        $this->assertSelectorNotExists('[data-testid="stats-closure-breakdowns"]');

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/details?scope=public&period=all_time&columns=hospital%2CclosureCount%2CsummedMinutes%2Creasons%2CclosureUnits&sortBy=actualMinutes&orderBy=asc&limit=50&'.$filterQuery,
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-closure-intervals"] th[aria-sort="ascending"]');
        $this->assertSelectorExists('[data-testid="closure-event-facet-reasons"].bg-gray-lt');
        $this->assertSelectorTextContains('[data-testid="closure-event-facet-reasons"]', 'No bed capacity');
        $this->assertSelectorTextContains('[data-testid="closure-event-facet-closureUnits"]', 'Functional unit');
        $this->assertSelectorTextContains('[data-testid="stats-closure-intervals"]', '50 records');

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/timeline/frame?scope=public&period=all_time&timeline_grain=year');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('turbo-frame#stats-closure-timeline', '2026');
        $this->assertSelectorExists('.closure-timeline-scroll--years');
        $this->assertSelectorExists('[data-testid="closure-timeline-grid"]');
        $this->assertSelectorExists('.closure-timeline-cell--closed');
        $this->assertSelectorExists('a[data-turbo-frame="stats-closure-timeline"]');
        $this->assertSelectorTextContains('.closure-timeline-legend', 'No closure represented');

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/timeline/frame?scope=public&period=all',
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="closure-timeline-grid"]');
        $this->assertSelectorNotExists('.closure-timeline-scroll--years');
        $this->assertSelectorCount(1, '[data-testid="closure-timeline-breadcrumb"] .breadcrumb-item');

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/timeline/frame?scope=public&period=all&timeline_grain=month&timeline_from='.$this->currentMonthStart()->format('Y-m-d'),
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('.closure-timeline-cell--closed');
        $this->assertSelectorTextContains('turbo-frame#stats-closure-timeline', 'No closure intervals');

        foreach (['quarter', 'month', 'week', 'day', 'event'] as $grain) {
            $client->request(Request::METHOD_GET, sprintf(
                '/statistics/closure-analytics/timeline/frame?scope=public&period=all_time&timeline_grain=%s&timeline_from=2026-05-01',
                $grain,
            ));
            $this->assertResponseIsSuccessful();
            $this->assertSelectorExists('turbo-frame#stats-closure-timeline');
        }
        $this->assertSelectorExists('[data-testid="closure-timeline-segments"]');
        $this->assertSelectorExists('.closure-timeline-segment--group');
        $this->assertSelectorTextContains('.closure-timeline-legend', 'Group');
        $this->assertSelectorTextContains('.closure-timeline-legend', 'Cluster');
        $this->assertSelectorTextContains('.closure-timeline-legend', 'Individual closure');
        $this->assertSelectorCount(6, '[data-testid="closure-timeline-breadcrumb"] .breadcrumb-item');
        $this->assertSelectorTextSame('[data-testid="closure-timeline-breadcrumb"] .breadcrumb-item.active', 'Events');
        $this->assertSelectorCount(2, '.closure-timeline-department');
        $this->assertSelectorTextContains('.closure-timeline-department__label', 'Functional Closure Department A');
        $this->assertSelectorExists('.closure-timeline-department + .closure-timeline-lane + .closure-timeline-lane');
        $this->assertSelectorTextContains('[data-testid="closure-timeline-segments"]', 'SK1');
        $this->assertSelectorTextContains('[data-testid="closure-timeline-segments"]', 'SK2');

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/timeline/frame?scope=public&period=all_time&timeline_grain=week&timeline_from=2026-04-27',
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="closure-timeline-period-departments"]');
        $this->assertSelectorCount(7, '.closure-timeline-week-day');
        $this->assertSelectorCount(2, '[data-testid="closure-timeline-period-departments"] .closure-timeline-department');
        $this->assertSelectorCount(3, '.closure-timeline-week-lane');
        $this->assertSelectorCount(3, '.closure-timeline-week-lane .closure-timeline-segment');
        $this->assertSelectorExists('.closure-timeline-week-lane .closure-timeline-segment--group');
        $this->assertSelectorTextContains('.closure-timeline-legend', 'Group');
        $this->assertSelectorTextContains(
            '[data-testid="closure-timeline-period-departments"]',
            'Functional Closure Department A',
        );
        $this->assertSelectorTextContains('[data-testid="closure-timeline-period-departments"]', 'SK1');
        $this->assertSelectorTextContains('[data-testid="closure-timeline-period-departments"]', 'SK2');
        $this->assertSelectorTextNotContains('[data-testid="closure-timeline-period-departments"]', 'functional-group');
        $dayJumpUrl = $client->getCrawler()->filter('.closure-timeline-week-day')->first()->attr('href');
        self::assertNotNull($dayJumpUrl);
        self::assertStringContainsString('timeline_grain=day', $dayJumpUrl);

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/timeline/frame?scope=public&period=all_time&timeline_grain=month&timeline_from=2026-05-01',
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains(
            '[data-testid="closure-timeline-period-departments"]',
            'Departments and urgency levels over the month',
        );
        $this->assertSelectorCount(31, '.closure-timeline-week-day');
        $this->assertSelectorCount(3, '.closure-timeline-week-lane');

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/timeline/frame?scope=public&period=all_time&timeline_grain=quarter&timeline_from=2026-04-01',
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains(
            '[data-testid="closure-timeline-period-departments"]',
            'Departments and urgency levels over the quarter',
        );
        self::assertGreaterThanOrEqual(13, $client->getCrawler()->filter('.closure-timeline-week-day')->count());
        $this->assertSelectorCount(3, '.closure-timeline-week-lane');
        $weekJumpUrl = null;
        foreach ($client->getCrawler()->filter('.closure-timeline-week-day')->each(
            static fn ($node): array => ['label' => trim($node->text()), 'url' => $node->attr('href')],
        ) as $weekLink) {
            if ('KW 18' === $weekLink['label']) {
                $weekJumpUrl = $weekLink['url'];
                break;
            }
        }
        self::assertNotNull($weekJumpUrl);
        self::assertStringContainsString('timeline_grain=week', $weekJumpUrl);
        self::assertStringContainsString('timeline_from=2026-04-27', $weekJumpUrl);

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/timeline/frame?scope=public&period=all_time&timeline_grain=day&timeline_from=2026-05-01&'.$filterQuery,
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.closure-timeline-department__label', 'Functional Closure Department A');
        $this->assertSelectorTextNotContains('[data-testid="closure-timeline-segments"]', 'Functional Closure Department B');
        $segmentUrl = $client->getCrawler()->filter('.closure-timeline-segment')->attr('href');
        self::assertNotNull($segmentUrl);
        self::assertStringContainsString('/statistics/closure-analytics/events/', $segmentUrl);
        self::assertStringContainsString('closureDepartments', $segmentUrl);
        self::assertStringContainsString('closureSpecialities', $segmentUrl);
        self::assertStringContainsString('closureCareLevels', $segmentUrl);
        self::assertStringContainsString('closureReasons', $segmentUrl);
        $breadcrumbUrls = $client->getCrawler()
            ->filter('[data-testid="closure-timeline-breadcrumb"] a')
            ->each(static fn ($node): ?string => $node->attr('href'));
        self::assertCount(4, $breadcrumbUrls);
        foreach ($breadcrumbUrls as $breadcrumbUrl) {
            self::assertNotNull($breadcrumbUrl);
            self::assertStringContainsString('closureDepartments', $breadcrumbUrl);
            self::assertStringContainsString('closureSpecialities', $breadcrumbUrl);
            self::assertStringContainsString('closureCareLevels', $breadcrumbUrl);
            self::assertStringContainsString('closureReasons', $breadcrumbUrl);
        }
        self::assertStringContainsString('timeline_grain=quarter', $breadcrumbUrls[1]);
        self::assertStringContainsString('timeline_from=2026-04-01', $breadcrumbUrls[1]);
        self::assertStringContainsString('timeline_grain=week', $breadcrumbUrls[3]);
        self::assertStringContainsString('timeline_from=2026-04-27', $breadcrumbUrls[3]);

        $client->request(Request::METHOD_GET, sprintf(
            '/statistics/closure-analytics/events/%s?scope=public&period=all_time&%s',
            rawurlencode('group:'.$hospitalId.':functional-group'),
            $filterQuery,
        ));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="closure-event-title"]', 'functional-group');
        $this->assertSelectorExists('[data-testid="closure-event-timeline"]');
        $this->assertSelectorExists('[data-testid="closure-event-timeline"] .closure-timeline-hours');
        $this->assertSelectorTextContains('[data-testid="closure-event-timeline"]', '00:00');
        $this->assertSelectorExists('.closure-timeline-segment--grouped');
        $this->assertSelectorCount(1, 'table tbody tr');
        $this->assertSelectorExists('[data-testid="catalog-actions"]');
        $this->assertSelectorExists('.col-lg-4 [data-testid="closure-event-kpis"]');
        $this->assertSelectorNotExists('.col-lg-8 [data-testid="closure-event-kpis"]');
        $this->assertSelectorTextContains('[data-testid="closure-event-kpis"]', 'Individual closures');
        $this->assertSelectorTextContains('[data-testid="closure-event-kpis"]', 'Sum of individual durations');
        $this->assertSelectorTextContains('[data-testid="closure-event-kpis"]', 'Actual closure time');
        $this->assertSelectorTextNotContains('[data-testid="closure-event-kpis"]', 'Observed time with at least one closure');
        $this->assertSelectorCount(1, '.col-lg-4 [data-testid="closure-event-kpis"]');
        $sidebarHtml = $client->getCrawler()->filter('[data-testid="closure-event-detail"] > .col-lg-4')->html();
        self::assertLessThan(
            strpos($sidebarHtml, 'closure-event-kpis'),
            strpos($sidebarHtml, 'catalog-actions'),
        );
        $allocationsUrl = $client->getCrawler()->filter('[data-testid="closure-event-allocations"]')->attr('href');
        self::assertNotNull($allocationsUrl);
        self::assertStringContainsString('/explore/allocation', $allocationsUrl);
        self::assertStringContainsString('hospitalFilter=my_hospitals', $allocationsUrl);
        self::assertStringContainsString('createdFrom=2026-05-01', $allocationsUrl);
        self::assertStringContainsString('createdUntil=2026-05-01', $allocationsUrl);

        $client->request(Request::METHOD_GET, sprintf(
            '/statistics/closure-analytics/intervals/%d?scope=public&period=all_time',
            $intervalId,
        ));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-closure-interval-detail"]', 'Functional Closure Department');
        $this->assertSelectorExists('[data-testid="closure-event-timeline"]');
        $this->assertSelectorTextContains('[data-testid="closure-event-timeline"]', '00:00');
        $intervalAllocationsUrl = $client->getCrawler()->filter('[data-testid="closure-event-allocations"]')->attr('href');
        self::assertNotNull($intervalAllocationsUrl);
        self::assertStringContainsString('/explore/allocation', $intervalAllocationsUrl);
        self::assertStringContainsString('createdFrom=2026-05-01', $intervalAllocationsUrl);
    }

    public function testCoincidentUngroupedClosuresRenderAsClusterWithSharedDetail(): void
    {
        $client = self::createClient();
        $user = $this->loginAsClosureBetaUser($client);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Functional Cluster Hospital',
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);
        $speciality = SpecialityFactory::createOne(['name' => 'Functional Cluster Speciality']);
        $departmentA = DepartmentFactory::createOne(['name' => 'Functional Cluster Department A']);
        $departmentB = DepartmentFactory::createOne(['name' => 'Functional Cluster Department B']);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $connection = self::getContainer()->get(Connection::class);

        foreach ([$departmentA, $departmentB] as $department) {
            $connection->insert('closure_interval', [
                'hospital_id' => $hospital->getId(),
                'import_id' => $import->getId(),
                'speciality_id' => $speciality->getId(),
                'department_id' => $department->getId(),
                'starts_at' => '2026-07-15 10:00:00',
                'ends_at' => '2026-07-15 12:00:00',
                'care_level' => 'emergency',
                'reason' => 'no_bed_capacity',
                'facility_kind' => 'clinic',
                'closure_unit' => 'Cluster unit',
                'source_group_id' => null,
                'source_recorded_at' => '2026-07-15 09:00:00',
                'source_changed_at' => '2026-07-15 09:00:00',
            ]);
        }

        $query = sprintf(
            'scope=hospital&hospital=%d&period=month&year=2026&month=7',
            $hospital->getId(),
        );
        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/details?'.$query.'&columns=startsAt%2Cevent%2CactualMinutes%2Cspecialities%2Cdepartments%2Creasons%2CclosureUnits',
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-closure-intervals"]', 'Cluster');
        $this->assertSelectorExists('[data-testid="stats-closure-intervals"] a.badge.bg-azure-lt.text-azure');
        $this->assertSelectorExists('[data-testid="closure-event-facet-departments"] .dropdown');
        $this->assertSelectorTextContains('[data-testid="closure-event-facet-departments"]', 'Functional Cluster Department A');
        $this->assertSelectorExists('[data-testid="closure-event-facet-departments"] .bg-red-lt');
        $this->assertSelectorTextContains('[data-testid="closure-event-facet-departments"]', 'Emergency Care');
        $this->assertSelectorTextContains('[data-testid="closure-event-facet-specialities"]', 'Functional Cluster Speciality');
        $this->assertSelectorTextContains('[data-testid="closure-event-facet-reasons"]', 'No bed capacity');
        $this->assertSelectorTextContains('[data-testid="closure-event-facet-closureUnits"]', 'Cluster unit');
        $clusterUrl = $client->getCrawler()
            ->filter('[data-testid="stats-closure-intervals"] a.badge[href*="/statistics/closure-analytics/events/"]')
            ->attr('href');
        self::assertNotNull($clusterUrl);
        self::assertStringContainsString('/events/cluster:', $clusterUrl);

        $client->request(Request::METHOD_GET, $clusterUrl);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="closure-event-title"]', 'Cluster of simultaneous closures');
        $this->assertSelectorCount(2, 'table tbody tr');
        $clusterAllocationsUrl = $client->getCrawler()->filter('[data-testid="closure-event-allocations"]')->attr('href');
        self::assertNotNull($clusterAllocationsUrl);
        self::assertStringContainsString('hospitalFilter='.$hospital->getId(), $clusterAllocationsUrl);
        self::assertStringContainsString('createdFrom=2026-07-15', $clusterAllocationsUrl);
        self::assertStringContainsString('createdUntil=2026-07-15', $clusterAllocationsUrl);

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/details?'.$query.'&tableView=intervals');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-closure-intervals"]', 'Individual closure intervals');
        $this->assertSelectorCount(2, '[data-testid="stats-closure-event-row"]');
        $this->assertSelectorTextContains('[data-testid="stats-closure-intervals"]', 'Functional Cluster Speciality');
        $this->assertSelectorTextContains('[data-testid="stats-closure-intervals"]', 'Functional Cluster Department A');
        $this->assertSelectorTextContains('[data-testid="stats-closure-intervals"]', 'Functional Cluster Department B');
        $this->assertSelectorTextContains('[data-testid="stats-closure-intervals"]', 'Cluster unit');
        $intervalSortUrl = $client->getCrawler()
            ->filter('[data-testid="stats-closure-intervals"] th a')
            ->last()
            ->attr('href');
        self::assertNotNull($intervalSortUrl);
        self::assertStringContainsString('tableView=intervals', $intervalSortUrl);
        self::assertStringContainsString('scope=hospital', $intervalSortUrl);
        self::assertStringContainsString('period=month', $intervalSortUrl);

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/timeline/frame?'.$query.'&timeline_grain=day&timeline_from=2026-07-15',
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.closure-timeline-segment--cluster');
        $timelineUrl = $client->getCrawler()->filter('.closure-timeline-segment')->first()->attr('href');
        self::assertNotNull($timelineUrl);
        self::assertStringContainsString('/statistics/closure-analytics/events/', $timelineUrl);
    }

    public function testEventDetailTimelineLinksSameDayDepartmentContext(): void
    {
        $client = self::createClient();
        $user = $this->loginAsClosureBetaUser($client);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Same Day Context Hospital',
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);
        $speciality = SpecialityFactory::createOne(['name' => 'Same Day Speciality']);
        $department = DepartmentFactory::createOne(['name' => 'Same Day Department']);
        $otherDepartment = DepartmentFactory::createOne(['name' => 'Other Day Department']);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $connection = self::getContainer()->get(Connection::class);

        $connection->insert('closure_interval', [
            'hospital_id' => $hospital->getId(),
            'import_id' => $import->getId(),
            'speciality_id' => $speciality->getId(),
            'department_id' => $department->getId(),
            'starts_at' => '2026-08-12 10:00:00',
            'ends_at' => '2026-08-12 12:00:00',
            'care_level' => 'emergency',
            'reason' => 'no_bed_capacity',
            'facility_kind' => 'clinic',
            'closure_unit' => 'Context unit',
            'source_group_id' => 'same-day-group',
            'source_recorded_at' => '2026-08-12 09:00:00',
            'source_changed_at' => '2026-08-12 09:00:00',
        ]);
        $connection->insert('closure_interval', [
            'hospital_id' => $hospital->getId(),
            'import_id' => $import->getId(),
            'speciality_id' => $speciality->getId(),
            'department_id' => $department->getId(),
            'starts_at' => '2026-08-12 14:00:00',
            'ends_at' => '2026-08-12 16:00:00',
            'care_level' => 'emergency',
            'reason' => 'no_bed_capacity',
            'facility_kind' => 'clinic',
            'closure_unit' => 'Context unit',
            'source_group_id' => null,
            'source_recorded_at' => '2026-08-12 13:00:00',
            'source_changed_at' => '2026-08-12 13:00:00',
        ]);
        $connection->insert('closure_interval', [
            'hospital_id' => $hospital->getId(),
            'import_id' => $import->getId(),
            'speciality_id' => $speciality->getId(),
            'department_id' => $otherDepartment->getId(),
            'starts_at' => '2026-08-12 18:00:00',
            'ends_at' => '2026-08-12 19:00:00',
            'care_level' => 'emergency',
            'reason' => 'no_bed_capacity',
            'facility_kind' => 'clinic',
            'closure_unit' => 'Context unit',
            'source_group_id' => null,
            'source_recorded_at' => '2026-08-12 17:00:00',
            'source_changed_at' => '2026-08-12 17:00:00',
        ]);

        $eventKey = rawurlencode('group:'.$hospital->getId().':same-day-group');
        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/events/'.$eventKey.'?scope=public&period=all_time',
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="closure-timeline-context"]');
        $this->assertSelectorExists('[data-testid="closure-timeline-context-legend"]');
        $this->assertSelectorTextContains('[data-testid="closure-timeline-context-legend"]', 'Other individual closure');
        $contextUrl = $client->getCrawler()->filter('[data-testid="closure-timeline-context"]')->attr('href');
        self::assertNotNull($contextUrl);
        self::assertStringContainsString('/statistics/closure-analytics/events/interval:', $contextUrl);
        self::assertSelectorTextNotContains('[data-testid="closure-event-timeline"]', 'Other Day Department');

        $client->request(Request::METHOD_GET, $contextUrl);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="closure-event-title"]', 'Ungrouped individual closure');
    }

    public function testEventAndIntervalDetailListOverlappingAllocationsByDepartmentWindow(): void
    {
        $client = self::createClient();
        $user = $this->loginAsClosureBetaUser($client);
        [$hospitalId, $intervalId, $departmentAId] = $this->seedGroupedClosures($user);
        $hospital = HospitalFactory::find($hospitalId);
        $departmentA = DepartmentFactory::find($departmentAId);
        $departmentB = DepartmentFactory::find(['name' => 'Functional Closure Department B']);
        $speciality = SpecialityFactory::find(['name' => 'Functional Closure Speciality A']);
        $import = ImportFactory::find(['hospital' => $hospital]);
        AssignmentFactory::createOne(['name' => 'Overlap Assign']);
        IndicationRawFactory::createOne(['name' => 'Overlap Raw', 'code' => 811]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'During Closure']);
        $defaults = [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $hospital->getState(),
            'dispatchArea' => $hospital->getDispatchArea(),
            'speciality' => $speciality,
            'urgency' => AllocationUrgency::EMERGENCY,
            'arrivalAt' => new \DateTimeImmutable('2026-05-01 11:30:00'),
        ];
        $included = AllocationFactory::createOne([
            ...$defaults,
            'department' => $departmentA,
            'createdAt' => new \DateTimeImmutable('2026-05-01 11:00:00'),
            'indicationNormalized' => $indication,
        ]);
        AllocationFactory::createOne([
            ...$defaults,
            'department' => $departmentB,
            'createdAt' => new \DateTimeImmutable('2026-05-01 11:15:00'),
        ]);
        AllocationFactory::createOne([
            ...$defaults,
            'department' => $departmentA,
            'createdAt' => new \DateTimeImmutable('2026-05-01 09:00:00'),
        ]);

        $eventKey = rawurlencode('group:'.$hospitalId.':functional-group');
        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/events/'.$eventKey.'?scope=public&period=all_time',
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="closure-overlapping-allocations"]');
        $this->assertSelectorNotExists('[data-testid="closure-overlapping-allocations-empty"]');
        $this->assertSelectorCount(2, '[data-testid="closure-overlapping-allocation-row"]');
        $this->assertSelectorTextContains('[data-testid="closure-overlapping-allocations"]', 'During Closure');
        $link = $client->getCrawler()->filter('[data-testid="closure-overlapping-allocation-link"]')->attr('href');
        self::assertNotNull($link);
        self::assertStringContainsString('/explore/allocation/'.$included->getPublicIdString(), $link);

        $client->request(
            Request::METHOD_GET,
            sprintf('/statistics/closure-analytics/intervals/%d?scope=public&period=all_time', $intervalId),
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(1, '[data-testid="closure-overlapping-allocation-row"]');
        $this->assertSelectorTextContains('[data-testid="closure-overlapping-allocations"]', 'Functional Closure Department A');
        $this->assertSelectorTextNotContains('[data-testid="closure-overlapping-allocations"]', 'Functional Closure Department B');

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/events/'.rawurlencode('group:'.$hospitalId.':current-month-group').'?scope=public&period=all_time',
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="closure-overlapping-allocations-empty"]');
        $this->assertSelectorTextContains(
            '[data-testid="closure-overlapping-allocations-empty"]',
            'No allocations during this closure',
        );
    }

    private function currentMonthStart(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('first day of this month 00:00:00', new \DateTimeZone('Europe/Berlin'));
    }

    private function loginAsClosureBetaUser(KernelBrowser $client): User
    {
        $user = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::CLOSURE_BETA],
        ]);
        $client->followRedirects(true);
        $client->loginUser($user);

        return $user;
    }

    /**
     * @return array{int, int, int, int}
     */
    private function seedGroupedClosures(User $user): array
    {
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Functional Closure Hospital',
            'state' => $state,
            'dispatchArea' => $dispatch,
            'owner' => $user,
            'createdBy' => $user,
        ]);
        $specialityA = SpecialityFactory::createOne(['name' => 'Functional Closure Speciality A']);
        $specialityB = SpecialityFactory::createOne(['name' => 'Functional Closure Speciality B']);
        $departmentA = DepartmentFactory::createOne(['name' => 'Functional Closure Department A']);
        $departmentB = DepartmentFactory::createOne(['name' => 'Functional Closure Department B']);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $connection = self::getContainer()->get(Connection::class);

        foreach ([
            [$departmentA, $specialityA, 'emergency'],
            [$departmentA, $specialityA, 'inpatient'],
            [$departmentB, $specialityB, 'inpatient'],
        ] as [$department, $speciality, $careLevel]) {
            $connection->insert('closure_interval', [
                'hospital_id' => $hospital->getId(),
                'import_id' => $import->getId(),
                'speciality_id' => $speciality->getId(),
                'department_id' => $department->getId(),
                'starts_at' => '2026-05-01 10:00:00',
                'ends_at' => '2026-05-01 12:00:00',
                'care_level' => $careLevel,
                'reason' => 'no_bed_capacity',
                'facility_kind' => 'clinic',
                'closure_unit' => 'Functional unit',
                'source_group_id' => 'functional-group',
                'source_recorded_at' => '2026-05-01 09:00:00',
                'source_changed_at' => '2026-05-01 09:00:00',
            ]);
        }
        $currentMonthClosure = $this->currentMonthStart()->modify('+9 days')->setTime(10, 0);
        $connection->insert('closure_interval', [
            'hospital_id' => $hospital->getId(),
            'import_id' => $import->getId(),
            'speciality_id' => $specialityB->getId(),
            'department_id' => $departmentB->getId(),
            'starts_at' => $currentMonthClosure->format('Y-m-d H:i:s'),
            'ends_at' => $currentMonthClosure->modify('+2 hours')->format('Y-m-d H:i:s'),
            'care_level' => 'inpatient',
            'reason' => 'no_bed_capacity',
            'facility_kind' => 'clinic',
            'closure_unit' => 'Functional unit',
            'source_group_id' => 'current-month-group',
            'source_recorded_at' => $currentMonthClosure->modify('-1 hour')->format('Y-m-d H:i:s'),
            'source_changed_at' => $currentMonthClosure->modify('-1 hour')->format('Y-m-d H:i:s'),
        ]);

        $intervalId = (int) $connection->fetchOne(
            'SELECT MIN(id) FROM closure_interval WHERE hospital_id = :hospital_id',
            ['hospital_id' => $hospital->getId()],
        );

        return [$hospital->getId(), $intervalId, $departmentA->getId(), $specialityA->getId()];
    }
}
