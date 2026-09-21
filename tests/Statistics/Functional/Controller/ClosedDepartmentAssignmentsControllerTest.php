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
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ClosedDepartmentAssignmentsControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testPageShowsKpisAndLazyFramesWithoutDetailCards(): void
    {
        $client = self::createClient();
        $this->seedClosedAllocations();

        $this->loginAsRoleUser($client);
        $client->enableProfiler();
        $crawler = $client->request(Request::METHOD_GET, '/statistics/closed-department-assignments?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-closed-department-heading-subtitle"]', 'Assignments despite a closed department');
        $this->assertSelectorTextContains('[data-testid="stats-closed-department-heading-title"]', 'Forced assignments');
        $this->assertSelectorTextContains('[data-testid="stats-closed-department-kpis"]', '4');
        $this->assertSelectorTextContains('[data-testid="stats-closed-department-kpi-share"]', '%');
        $this->assertSelectorExists('[data-testid="stats-closed-department-kpi-mean-transport"]');
        $this->assertSelectorExists('[data-testid="stats-closed-department-kpi-departments"]');
        $this->assertSelectorNotExists('[data-testid="stats-closed-department-explore-all"]');
        $this->assertSelectorExists('[data-testid="stats-closed-department-time-series"]');
        $this->assertSelectorExists('[data-testid="stats-closed-department-heatmap"]');

        $rankingsFrame = $crawler->filter('[data-testid="stats-closed-department-rankings-frame"]');
        self::assertSame('_top', $rankingsFrame->attr('target'));
        self::assertSame('lazy', $rankingsFrame->attr('loading'));
        self::assertStringContainsString('/statistics/closed-department-assignments/rankings', (string) $rankingsFrame->attr('src'));
        self::assertStringContainsString('closedCount=4', (string) $rankingsFrame->attr('src'));
        $this->assertSelectorExists('[data-testid="stats-closed-department-rankings-placeholder"] .placeholder-glow');
        $this->assertSelectorNotExists('[data-testid="stats-closed-department-rankings"]');

        foreach ([
            'stats-closed-department-departments',
            'stats-closed-department-specialities',
            'stats-closed-department-indications',
            'stats-closed-department-occasions',
            'stats-closed-department-assignments',
            'stats-closed-department-infections',
        ] as $cardTestId) {
            $this->assertSelectorNotExists('[data-testid="'.$cardTestId.'-frame"]');
            $this->assertSelectorNotExists('[data-testid="'.$cardTestId.'"] .progress');
        }

        $dispatchFrame = $crawler->filter('[data-testid="stats-closed-department-dispatch-areas-frame"]');
        self::assertSame('_top', $dispatchFrame->attr('target'));
        self::assertSame('lazy', $dispatchFrame->attr('loading'));
        self::assertStringContainsString('/statistics/closed-department-assignments/cards/dispatch-area', (string) $dispatchFrame->attr('src'));
        self::assertStringContainsString('closedCount=4', (string) $dispatchFrame->attr('src'));
        $this->assertSelectorExists('[data-testid="stats-closed-department-dispatch-areas-placeholder"] .placeholder-glow');
        $this->assertSelectorNotExists('[data-testid="stats-closed-department-dispatch-areas"] .progress');

        $detailsFrame = $crawler->filter('[data-testid="stats-closed-department-details-frame"]');
        self::assertSame('_top', $detailsFrame->attr('target'));
        self::assertSame('lazy', $detailsFrame->attr('loading'));
        self::assertStringContainsString('/statistics/closed-department-assignments/details', (string) $detailsFrame->attr('src'));
        $this->assertSelectorExists('[data-testid="stats-closed-department-details-placeholder"] .placeholder-glow');

        $this->assertSelectorNotExists('[data-testid="stats-closed-department-gender"]');
        $this->assertSelectorNotExists('[data-testid="stats-closed-department-inline-compare-hint"]');

        $payloadJson = (string) $crawler->filter('[data-controller="closed-department-assignments-charts"]')
            ->attr('data-closed-department-assignments-charts-payload-value');
        $payload = json_decode(html_entity_decode($payloadJson, ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('timeSeries', $payload);
        self::assertNotSame([], $payload['timeSeries']['counts']);
        self::assertArrayHasKey('heatmap', $payload);
        self::assertCount(12, $payload['heatmap']['columnLabels']);
        self::assertCount(7, $payload['heatmap']['rowLabels']);
        self::assertSame('00–02', $payload['heatmap']['columnLabels'][0]);
        self::assertSame('22–24', $payload['heatmap']['columnLabels'][11]);
        self::assertArrayNotHasKey('transport', $payload);

        $profile = $client->getProfile();
        self::assertNotNull($profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        $sqlParts = [];
        foreach ($collector->getQueries() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $sqlParts[] = (string) ($query['sql'] ?? '');
            }
        }
        $projectionSelects = [];
        foreach ($sqlParts as $sql) {
            $normalized = strtolower($sql);
            $trimmed = ltrim($normalized, " \t\n\r\0\x0B\"");
            if (!str_starts_with($trimmed, 'select') || !str_contains($normalized, 'from allocation_stats_projection')) {
                continue;
            }
            $projectionSelects[] = $normalized;
        }
        $sqlBlob = implode("\n", $projectionSelects);
        self::assertStringNotContainsString("select 'department'", $sqlBlob);
        self::assertStringNotContainsString("select 'indication'", $sqlBlob);
        self::assertStringNotContainsString("select 'dispatch_area'", $sqlBlob);
        self::assertStringNotContainsString('percentile_cont', $sqlBlob);
        self::assertStringNotContainsString('gender_code', $sqlBlob);
        self::assertStringContainsString('grouping sets', $sqlBlob);
        self::assertStringNotContainsString('select distinct hospital_id', $sqlBlob);
        self::assertStringNotContainsString('group by hospital_id', $sqlBlob);
        $this->assertSelectorExists('[data-controller="data-quality-indicator"]');
        $this->assertSelectorExists('[data-testid="stats-data-quality-indicator-badge"]');
    }

    public function testDefaultPeriodIsLast12MonthsWhenPeriodIsOmitted(): void
    {
        $client = self::createClient();
        $this->seedClosedAllocations();

        $this->loginAsRoleUser($client);
        $client->request(Request::METHOD_GET, '/statistics/closed-department-assignments?scope=public');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-analysis-context-period-summary"]', 'Last 12 months');
        $this->assertSelectorTextContains('[data-testid="stats-closed-department-kpis"]', '4');
    }

    public function testRankingsFrameRendersAllSixCards(): void
    {
        $client = self::createClient();
        $this->seedClosedAllocations();

        $this->loginAsRoleUser($client);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/closed-department-assignments/rankings?scope=public&period=all&closedCount=4',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('turbo-frame#stats-closed-department-rankings');
        $this->assertSelectorExists('[data-testid="stats-closed-department-rankings"]');

        foreach ([
            'stats-closed-department-departments',
            'stats-closed-department-specialities',
            'stats-closed-department-indications',
            'stats-closed-department-occasions',
            'stats-closed-department-assignments',
            'stats-closed-department-infections',
        ] as $cardTestId) {
            $this->assertSelectorExists('[data-testid="'.$cardTestId.'"] .progress');
        }

        $indicationsTopListHref = (string) $crawler->filter('[data-testid="stats-closed-department-indications-top-list"]')->attr('href');
        self::assertStringContainsString('/statistics/top-lists/top_diagnoses', $indicationsTopListHref);
        self::assertStringContainsString('departmentWasClosed=1', $indicationsTopListHref);
        self::assertStringNotContainsString('closedCount=', $indicationsTopListHref);
        $this->assertSelectorNotExists('[data-testid="stats-closed-department-dispatch-areas"]');
    }

    public function testNamedCardFrameRendersShareList(): void
    {
        $client = self::createClient();
        $this->seedClosedAllocations();

        $this->loginAsRoleUser($client);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/closed-department-assignments/cards/indication?scope=public&period=all&closedCount=4',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('turbo-frame#stats-closed-department-indications');
        $this->assertSelectorExists('[data-testid="stats-closed-department-indications"] .progress');
        $this->assertSelectorExists('[data-testid="stats-closed-department-indications-top-list"]');
        $this->assertSelectorTextContains('[data-testid="stats-closed-department-indications"]', '%');

        $indicationsTopListHref = (string) $crawler->filter('[data-testid="stats-closed-department-indications-top-list"]')->attr('href');
        self::assertStringContainsString('/statistics/top-lists/top_diagnoses', $indicationsTopListHref);
        self::assertStringContainsString('departmentWasClosed=1', $indicationsTopListHref);
        self::assertStringNotContainsString('closedCount=', $indicationsTopListHref);
    }

    public function testDispatchAreaCardHasNoTopListLink(): void
    {
        $client = self::createClient();
        $this->seedClosedAllocations();

        $this->loginAsRoleUser($client);
        $client->request(
            Request::METHOD_GET,
            '/statistics/closed-department-assignments/cards/dispatch-area?scope=public&period=all&closedCount=4',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-closed-department-dispatch-areas"] .progress');
        $this->assertSelectorTextContains('[data-testid="stats-closed-department-dispatch-areas"]', '%');
        $this->assertSelectorNotExists('[data-testid="stats-closed-department-dispatch-areas-top-list"]');
    }

    public function testDetailsFrameRendersContextAndTransport(): void
    {
        $client = self::createClient();
        $this->seedClosedAllocations();

        $this->loginAsRoleUser($client);
        $crawler = $client->request(Request::METHOD_GET, '/statistics/closed-department-assignments/details?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('turbo-frame#stats-closed-department-details');
        self::assertCount(2, $crawler->filter('[data-testid="stats-closed-department-gender"] .progress-bar.bg-secondary'));
        self::assertCount(3, $crawler->filter('[data-testid="stats-closed-department-urgency"] .progress-bar.bg-secondary'));
        self::assertCount(4, $crawler->filter('[data-testid="stats-closed-department-gender"] .progress'));
        self::assertCount(6, $crawler->filter('[data-testid="stats-closed-department-urgency"] .progress'));
        $this->assertSelectorTextNotContains('[data-testid="stats-closed-department-gender"]', 'Other');
        $this->assertSelectorExists('[data-testid="stats-closed-department-resources"] .progress-bar.bg-secondary');
        $this->assertSelectorExists('[data-testid="stats-closed-department-clinical"] .progress-bar.bg-secondary');
        $this->assertSelectorNotExists('[data-testid="stats-closed-department-dispatch-areas"]');
        $this->assertSelectorExists('[data-testid="stats-closed-department-inline-compare-hint"]');
        $this->assertSelectorTextContains('[data-testid="stats-closed-department-urgency"]', '+100,0%');
        $this->assertSelectorExists('[data-testid="stats-closed-department-urgency"] .text-green');
        $this->assertSelectorExists('[data-testid="stats-closed-department-urgency"] .text-red');

        $payloadJson = (string) $crawler->filter('[data-controller="closed-department-assignments-charts"]')
            ->attr('data-closed-department-assignments-charts-payload-value');
        $payload = json_decode(html_entity_decode($payloadJson, ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('transport', $payload);
        self::assertNotSame([], $payload['transport']['labels']);
        self::assertCount(count($payload['transport']['labels']), $payload['transport']['closedShares']);
        self::assertCount(count($payload['transport']['labels']), $payload['transport']['totalShares']);
    }

    public function testEmptyStateWhenThereAreNoClosedAssignments(): void
    {
        $client = self::createClient();
        $this->seedRegularOnlyAllocations();

        $this->loginAsRoleUser($client);
        $client->request(Request::METHOD_GET, '/statistics/closed-department-assignments?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-closed-department-empty"]');
        $this->assertSelectorNotExists('[data-testid="stats-closed-department-rankings-frame"]');
        $this->assertSelectorNotExists('[data-testid="stats-closed-department-rankings"]');
        $this->assertSelectorNotExists('[data-testid="stats-closed-department-details-frame"]');
        $this->assertSelectorNotExists('[data-testid="stats-closed-department-dispatch-areas-frame"]');
        $this->assertSelectorNotExists('[data-testid="stats-closed-department-inline-compare-hint"]');
        $this->assertSelectorTextContains('[data-testid="stats-closed-department-kpi-share"]', '0');
    }

    public function testParticipantSeesExploreLink(): void
    {
        $client = self::createClient();
        $this->seedClosedAllocations();

        $this->loginAsParticipant($client);
        $client->request(Request::METHOD_GET, '/statistics/closed-department-assignments?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-closed-department-explore-all"]');
    }

    private function seedClosedAllocations(): void
    {
        $context = $this->seedGraph();
        AllocationFactory::createMany(4, [
            'departmentWasClosed' => true,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-04-01 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 09:20:00'),
        ] + $context['allocation']);
        AllocationFactory::createMany(6, [
            'departmentWasClosed' => false,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'createdAt' => new \DateTimeImmutable('2026-04-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 10:18:00'),
        ] + $context['allocation']);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)
            ->rebuildForImport($context['importId']);
    }

    private function seedRegularOnlyAllocations(): void
    {
        $context = $this->seedGraph();
        AllocationFactory::createMany(3, [
            'departmentWasClosed' => false,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'createdAt' => new \DateTimeImmutable('2026-04-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 10:18:00'),
        ] + $context['allocation']);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)
            ->rebuildForImport($context['importId']);
    }

    /**
     * @return array{importId: int, allocation: array<string, object>}
     */
    private function seedGraph(): array
    {
        $user = UserFactory::createOne(['username' => 'cda-ctrl-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'CdaCtrlState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'CdaCtrlDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CdaCtrlHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        $speciality = SpecialityFactory::createOne(['name' => 'CdaCtrlSpec']);
        $department = DepartmentFactory::createOne(['name' => 'CdaCtrlDept']);
        $assignment = AssignmentFactory::createOne(['name' => 'CdaCtrlAssign']);
        $infection = InfectionFactory::createOne(['name' => 'CdaCtrlInfection']);
        IndicationRawFactory::createOne(['name' => 'CdaCtrlRaw', 'code' => 912_702]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'CdaCtrlIndication']);
        $occasion = OccasionFactory::createOne(['name' => 'CdaCtrlOccasion']);
        $import = ImportFactory::createOne(['name' => 'CdaCtrlImport', 'hospital' => $hospital, 'createdBy' => $user]);

        return [
            'importId' => $import->getId(),
            'allocation' => [
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatchArea,
                'department' => $department,
                'speciality' => $speciality,
                'assignment' => $assignment,
                'infection' => $infection,
                'indicationNormalized' => $indication,
                'occasion' => $occasion,
            ],
        ];
    }
}
