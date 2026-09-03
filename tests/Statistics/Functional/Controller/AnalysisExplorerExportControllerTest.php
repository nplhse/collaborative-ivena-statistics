<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
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
use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Tests\Support\RateLimit\DeniesRateLimiter;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\Tests\Support\Statistics\RefreshesStatisticsFunctionalDataTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AnalysisExplorerExportControllerTest extends WebTestCase
{
    use DeniesRateLimiter;
    use Factories;
    use InteractsWithAuthenticatedUser;
    use RefreshesStatisticsFunctionalDataTrait;

    public function testExportTableCsvReturnsStreamedCsv(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedProjectionWithAllocation();

        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/explorer?scope=public&period=all',
        );
        $this->assertResponseIsSuccessful();

        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        self::assertInstanceOf(ExplorerConfigMapper::class, $mapper);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        self::assertInstanceOf(DefaultAnalysisViewFactory::class, $viewFactory);

        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
        $state = $mapper->toStateArray($viewFactory->createDefault($filter));
        $token = $this->csrfToken($client, 'explorer_export_csv');

        $client->request(
            Request::METHOD_POST,
            '/statistics/analysis/explorer/export/table.csv?scope=public&period=all',
            [
                '_token' => $token,
                'appliedConfigState' => json_encode($state, \JSON_THROW_ON_ERROR),
            ],
        );

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/csv; charset=UTF-8');

        $disposition = $client->getResponse()->headers->get('Content-Disposition');
        self::assertNotNull($disposition);
        self::assertStringContainsString('attachment', $disposition);
        self::assertStringContainsString('.csv', $disposition);
    }

    public function testExportRejectsInvalidCsrfToken(): void
    {
        $client = $this->createClientAsRoleUser();

        $client->request(
            Request::METHOD_POST,
            '/statistics/analysis/explorer/export/table.csv?scope=public&period=all',
            [
                '_token' => 'invalid-token',
                'appliedConfigState' => '{}',
            ],
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testExportIsRateLimited(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $user = $this->loginAsRoleUser($client);
        $userId = $user->getId();
        self::assertNotNull($userId);

        $client->request(Request::METHOD_GET, '/statistics/analysis/explorer?scope=public&period=all');
        self::assertResponseIsSuccessful();
        $token = $this->csrfToken($client, 'explorer_export_csv');

        $this->denyRateLimiter(
            'limiter.analysis_explorer_export',
            $this->userAndIpRateLimitKey('analysis_explorer_export', $userId),
        );

        $client->request(
            Request::METHOD_POST,
            '/statistics/analysis/explorer/export/table.csv?scope=public&period=all',
            [
                '_token' => $token,
                'appliedConfigState' => '{}',
            ],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testExportRespectsPeriodRestriction(): void
    {
        $client = $this->createAdminExplorerClient();
        $this->seedDiverseProjection();

        $yearCsv = $this->exportCsv($client, $this->analysisState([
            'period' => ['type' => 'year', 'year' => 2024, 'quarter' => null, 'month' => null],
        ]));
        $allTimeCsv = $this->exportCsv($client, $this->analysisState([
            'period' => ['type' => 'all_time', 'year' => null, 'quarter' => null, 'month' => null],
        ]));

        self::assertSame(3, $this->csvFooterTotal($yearCsv));
        self::assertSame(5, $this->csvFooterTotal($allTimeCsv));
    }

    public function testExportRespectsHospitalScope(): void
    {
        $client = $this->createAdminExplorerClient();
        $seed = $this->seedDiverseProjection();

        $hospitalCsv = $this->exportCsv($client, $this->analysisState([
            'scope' => ['group' => 'my_hospitals', 'detail' => (string) $seed['hospitalAId']],
        ]));
        $publicCsv = $this->exportCsv($client, $this->analysisState([
            'scope' => ['group' => 'public', 'detail' => null],
        ]));

        self::assertSame(4, $this->csvFooterTotal($hospitalCsv));
        self::assertSame(5, $this->csvFooterTotal($publicCsv));
    }

    public function testExportRespectsAnalysisFilters(): void
    {
        $client = $this->createAdminExplorerClient();
        $this->seedDiverseProjection();

        $filteredCsv = $this->exportCsv($client, $this->analysisState([
            'filters' => [
                ['dimensionKey' => 'urgency', 'operator' => 'equals', 'value' => AllocationUrgency::EMERGENCY->value],
            ],
        ]));
        $unfilteredCsv = $this->exportCsv($client, $this->analysisState([
            'filters' => [],
        ]));

        self::assertSame(4, $this->csvFooterTotal($filteredCsv));
        self::assertSame(5, $this->csvFooterTotal($unfilteredCsv));
        self::assertLessThan($this->csvFooterTotal($unfilteredCsv), $this->csvFooterTotal($filteredCsv));
    }

    public function testExportRespectsGroupingDimension(): void
    {
        $client = $this->createAdminExplorerClient();
        $this->seedDiverseProjection();

        $monthCsv = $this->exportCsv($client, $this->analysisState());
        $genderCsv = $this->exportCsv($client, $this->analysisState([
            'rows' => ['dimension' => 'gender', 'grain' => 'total'],
        ]));

        self::assertStringContainsString('Month,Allocations', $this->stripBom($monthCsv));
        self::assertStringContainsString('Gender,Allocations', $this->stripBom($genderCsv));
        self::assertStringContainsString('Male,4', $this->stripBom($genderCsv));
        self::assertStringContainsString('Female,1', $this->stripBom($genderCsv));
        self::assertSame(5, $this->csvFooterTotal($genderCsv));
    }

    public function testExportRespectsCombinedScopePeriodAndFilter(): void
    {
        $client = $this->createAdminExplorerClient();
        $seed = $this->seedDiverseProjection();

        $csv = $this->exportCsv($client, $this->analysisState([
            'scope' => ['group' => 'my_hospitals', 'detail' => (string) $seed['hospitalAId']],
            'period' => ['type' => 'year', 'year' => 2024, 'quarter' => null, 'month' => null],
            'filters' => [
                ['dimensionKey' => 'urgency', 'operator' => 'equals', 'value' => AllocationUrgency::EMERGENCY->value],
            ],
        ]));

        self::assertSame(1, $this->csvFooterTotal($csv));
        self::assertNotSame(5, $this->csvFooterTotal($csv));
    }

    public function testExportIgnoresUrlScopeAndPeriodOverlay(): void
    {
        $client = $this->createAdminExplorerClient();
        $seed = $this->seedDiverseProjection();

        $csv = $this->exportCsv($client, $this->analysisState([
            'scope' => ['group' => 'my_hospitals', 'detail' => (string) $seed['hospitalAId']],
            'period' => ['type' => 'year', 'year' => 2024, 'quarter' => null, 'month' => null],
        ]));

        self::assertSame(2, $this->csvFooterTotal($csv));
    }

    /**
     * @param array<string, mixed> $queryOverrides
     *
     * @return array<string, mixed>
     */
    private function analysisState(array $queryOverrides = []): array
    {
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        self::assertInstanceOf(ExplorerConfigMapper::class, $mapper);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        self::assertInstanceOf(DefaultAnalysisViewFactory::class, $viewFactory);

        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::AllTime,
        );
        $state = $mapper->toStateArray($viewFactory->createDefault($filter));
        foreach ($queryOverrides as $key => $value) {
            $state['query'][$key] = $value;
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function exportCsv(KernelBrowser $client, array $state): string
    {
        $token = $this->csrfToken($client, 'explorer_export_csv');
        $client->request(
            Request::METHOD_POST,
            '/statistics/analysis/explorer/export/table.csv?scope=public&period=all',
            [
                '_token' => $token,
                'appliedConfigState' => json_encode($state, \JSON_THROW_ON_ERROR),
            ],
        );

        self::assertResponseIsSuccessful();
        $content = $client->getInternalResponse()->getContent();
        self::assertNotSame('', $content);

        return $content;
    }

    private function csvFooterTotal(string $csv): int
    {
        $lines = preg_split('/\R/', trim($this->stripBom($csv)));
        self::assertIsArray($lines);
        self::assertNotEmpty($lines);
        $last = str_getcsv($lines[array_key_last($lines)] ?? '', ',', '"', '\\');
        self::assertSame('Total', $last[0] ?? '');

        return (int) $last[array_key_last($last)];
    }

    private function stripBom(string $csv): string
    {
        return str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
    }

    private function createAdminExplorerClient(): KernelBrowser
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/statistics/analysis/explorer?scope=public&period=all');
        $this->assertResponseIsSuccessful();

        return $client;
    }

    /**
     * @return array{hospitalAId: int, hospitalBId: int}
     */
    private function seedDiverseProjection(): array
    {
        $user = UserFactory::createOne(['username' => 'analysis-explorer-export-diverse']);
        $state = StateFactory::createOne(['name' => 'Explorer Export Diverse State', 'createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'Explorer Export Diverse Dispatch']);
        $hospitalA = HospitalFactory::createOne(['name' => 'Explorer Export Hospital A']);
        $hospitalB = HospitalFactory::createOne(['name' => 'Explorer Export Hospital B']);
        $importA = ImportFactory::createOne(['name' => 'Explorer Export Import A', 'hospital' => $hospitalA, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['name' => 'Explorer Export Import B', 'hospital' => $hospitalB, 'createdBy' => $user]);
        SpecialityFactory::createOne(['name' => 'Explorer Export Diverse Speciality']);
        DepartmentFactory::createOne(['name' => 'Explorer Export Diverse Department']);
        AssignmentFactory::createOne(['name' => 'Explorer Export Diverse Assignment']);
        OccasionFactory::createOne(['name' => 'Explorer Export Diverse Occasion']);
        InfectionFactory::createOne(['name' => 'Explorer Export Diverse Infection']);
        $raw = IndicationRawFactory::createOne(['name' => 'Explorer Export Diverse Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Explorer Export Diverse Normalized']);

        $defaults = [
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
        ];

        AllocationFactory::createOne(array_merge($defaults, [
            'createdAt' => new \DateTimeImmutable('2024-06-15 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2024-06-15 10:20:00'),
            'import' => $importA,
            'hospital' => $hospitalA,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
        ]));
        AllocationFactory::createOne(array_merge($defaults, [
            'createdAt' => new \DateTimeImmutable('2024-06-16 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2024-06-16 11:20:00'),
            'import' => $importA,
            'hospital' => $hospitalA,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
        ]));
        AllocationFactory::createOne(array_merge($defaults, [
            'createdAt' => new \DateTimeImmutable('2024-06-17 12:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2024-06-17 12:20:00'),
            'import' => $importB,
            'hospital' => $hospitalB,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
        ]));
        AllocationFactory::createOne(array_merge($defaults, [
            'createdAt' => new \DateTimeImmutable('2025-06-15 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-15 10:20:00'),
            'import' => $importA,
            'hospital' => $hospitalA,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
        ]));
        AllocationFactory::createOne(array_merge($defaults, [
            'createdAt' => new \DateTimeImmutable('2026-08-10 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-08-10 09:20:00'),
            'import' => $importA,
            'hospital' => $hospitalA,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
        ]));

        $hospitalAId = $hospitalA->getId();
        $hospitalBId = $hospitalB->getId();
        $importAId = $importA->getId();
        $importBId = $importB->getId();
        self::assertNotNull($hospitalAId);
        self::assertNotNull($hospitalBId);
        self::assertNotNull($importAId);
        self::assertNotNull($importBId);

        $this->rebuildProjectionForImports([(int) $importAId, (int) $importBId]);

        return [
            'hospitalAId' => (int) $hospitalAId,
            'hospitalBId' => (int) $hospitalBId,
        ];
    }

    private function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        $requestStack = $client->getContainer()->get('request_stack');
        $request = $client->getRequest();
        $requestStack->push($request);
        try {
            $token = $client->getContainer()->get('security.csrf.token_manager')->getToken($tokenId);
        } finally {
            $requestStack->pop();
        }

        return (string) $token->getValue();
    }

    private function seedProjectionWithAllocation(): void
    {
        $user = UserFactory::createOne(['username' => 'analysis-explorer-export-test']);
        $state = StateFactory::createOne(['name' => 'Explorer Export State', 'createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'Explorer Export Dispatch']);
        $hospital = HospitalFactory::createOne(['name' => 'Explorer Export Hospital']);
        $import = ImportFactory::createOne(['name' => 'Explorer Export Import', 'hospital' => $hospital, 'createdBy' => $user]);
        SpecialityFactory::createOne(['name' => 'Explorer Export Speciality']);
        DepartmentFactory::createOne(['name' => 'Explorer Export Department']);
        AssignmentFactory::createOne(['name' => 'Explorer Export Assignment']);
        OccasionFactory::createOne(['name' => 'Explorer Export Occasion']);
        InfectionFactory::createOne(['name' => 'Explorer Export Infection']);
        $raw = IndicationRawFactory::createOne(['name' => 'Explorer Export Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Explorer Export Normalized']);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('today'),
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
        ]);

        $this->rebuildProjectionForImports([(int) $import->getId()]);
    }
}
