<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
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
        $this->assertSelectorExists('[data-testid="stats-closure-empty"]');
        $this->assertSelectorNotExists('[data-testid="stats-closure-intervals"]');
        $this->assertSelectorExists('[data-testid="stats-closure-tabs"]');
        $this->assertSelectorExists('[data-testid="stats-closure-tab-overview"].active');
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

        self::assertResponseRedirects();
        self::assertStringContainsString('scope=public', (string) $client->getResponse()->headers->get('location'));
    }

    public function testPeriodStepNavigationIsAvailableOnAllClosurePages(): void
    {
        $client = self::createClient();
        $this->loginAsClosureBetaUser($client);

        foreach ([
            '/statistics/closure-analytics',
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
        $this->assertSelectorExists('.col-xl-8 [data-closure-analytics-charts-target="timeSeriesChart"]');
        $this->assertSelectorExists('.col-xl-8 [data-closure-analytics-charts-target="heatmapChart"]');
        $this->assertSelectorExists('[data-closure-analytics-charts-target="timeSeriesChart"]');
        $this->assertSelectorExists('[data-closure-analytics-charts-target="heatmapChart"]');
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
        $this->assertSelectorExists('[data-testid="stats-closure-units"] table.table-sm');
        $this->assertSelectorNotExists('[data-testid="stats-closure-units"] .card-footer');
        $this->assertSelectorNotExists('[data-testid="closure-filter-units"]');
        $this->assertSelectorNotExists('[data-testid="stats-closure-unit-timeline-link"]');
        $this->assertSelectorNotExists('turbo-frame#stats-closure-timeline');
        $this->assertSelectorNotExists('turbo-frame#stats-closure-details');
        $this->assertSelectorExists('[data-testid="stats-closure-tab-overview"].active');
        $this->assertSelectorExists('[data-testid="statistics-filters-drawer-trigger"]');
        self::assertStringContainsString('closedHours', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('closedShares', (string) $client->getResponse()->getContent());

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
        $this->assertSelectorExists('[data-testid="closure-filter-care-level-emergency"][checked]');
        $this->assertSelectorExists('[data-testid="closure-filter-reason-no_bed_capacity"][checked]');
        $this->assertSelectorTextContains('[data-testid="statistics-filters-active"]', 'Functional Closure Department A');
        $this->assertSelectorTextContains('[data-testid="statistics-filters-active"]', 'Functional Closure Speciality A');
        $this->assertSelectorTextContains('[data-testid="statistics-filters-active"]', 'SK1');
        $this->assertSelectorTextContains('[data-testid="statistics-filters-active"]', 'No bed capacity');
        $this->assertSelectorTextSame('[data-testid="statistics-filters-drawer-trigger"] .badge', '4');
        $resetUrl = $client->getCrawler()->filter('[data-testid="statistics-filters-clear"]')->attr('href');
        self::assertNotNull($resetUrl);
        self::assertStringContainsString('scope=public', $resetUrl);
        self::assertStringContainsString('period=all_time', $resetUrl);
        self::assertStringNotContainsString('closureDepartments', $resetUrl);
        self::assertStringNotContainsString('closureSpecialities', $resetUrl);
        self::assertStringNotContainsString('closureCareLevels', $resetUrl);
        self::assertStringNotContainsString('closureReasons', $resetUrl);
        self::assertStringNotContainsString('closureUnits', $resetUrl);
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
        $this->assertSelectorTextContains('[data-testid="stats-closure-intervals"]', 'functional-group');
        $this->assertSelectorTextSame('[data-testid="stats-closure-intervals"] tbody tr td:nth-child(4)', '1');
        $this->assertSelectorNotExists('[data-testid="stats-closure-breakdowns"]');

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
            '/statistics/closure-analytics/timeline/frame?scope=public&period=all&timeline_grain=month&timeline_from=2026-09-01',
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
        $this->assertSelectorExists('.closure-timeline-segment');
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
        $this->assertSelectorTextContains('h2.page-title', 'functional-group');
        $this->assertSelectorExists('[data-testid="closure-event-timeline"]');
        $this->assertSelectorExists('.closure-timeline-segment--grouped');
        $this->assertSelectorCount(1, 'table tbody tr');

        $client->request(Request::METHOD_GET, sprintf(
            '/statistics/closure-analytics/intervals/%d?scope=public&period=all_time',
            $intervalId,
        ));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-closure-interval-detail"]', 'Functional Closure Department');
    }

    private function loginAsClosureBetaUser(KernelBrowser $client): User
    {
        $user = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::CLOSURE_BETA],
        ]);
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
        $connection->insert('closure_interval', [
            'hospital_id' => $hospital->getId(),
            'import_id' => $import->getId(),
            'speciality_id' => $specialityB->getId(),
            'department_id' => $departmentB->getId(),
            'starts_at' => '2026-09-10 10:00:00',
            'ends_at' => '2026-09-10 12:00:00',
            'care_level' => 'inpatient',
            'reason' => 'no_bed_capacity',
            'facility_kind' => 'clinic',
            'closure_unit' => 'Functional unit',
            'source_group_id' => 'current-month-group',
            'source_recorded_at' => '2026-09-10 09:00:00',
            'source_changed_at' => '2026-09-10 09:00:00',
        ]);

        $intervalId = (int) $connection->fetchOne(
            'SELECT MIN(id) FROM closure_interval WHERE hospital_id = :hospital_id',
            ['hospital_id' => $hospital->getId()],
        );

        return [$hospital->getId(), $intervalId, $departmentA->getId(), $specialityA->getId()];
    }
}
