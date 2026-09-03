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
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\Tests\Support\Statistics\RefreshesStatisticsFunctionalDataTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class TopListsControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use RefreshesStatisticsFunctionalDataTrait;

    use Factories;

    public function testIndexListsAvailableTopLists(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(Request::METHOD_GET, '/statistics/top-lists?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-top-lists-index"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-card-top_diagnoses"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-card-top_departments"]');
        $this->assertSelectorNotExists('[data-testid="stats-top-lists-widget"]');
        $this->assertSelectorNotExists('[data-testid="stats-explorer-sidebar"]');
        $this->assertStringContainsString('Top Lists', $crawler->filter('[data-testid="stats-heading-title"]')->text());
        $href = $crawler->filter('[data-testid="stats-top-lists-card-top_diagnoses"]')->attr('href');
        $this->assertStringContainsString('/statistics/top-lists/top_diagnoses', $href);
        $this->assertSelectorNotExists('[data-testid="stats-scope"]');
        $this->assertSelectorNotExists('[data-testid="stats-scope-primary"]');
        $this->assertSelectorNotExists('[data-testid="stats-period-primary"]');
        $this->assertSelectorNotExists('[data-testid="stats-period-navigation"]');
        $this->assertSelectorTextContains(
            '[data-testid="stats-top-lists-card-top_diagnoses"]',
            'Most frequent indications among assignments, with count and share.',
        );
        $this->assertSelectorTextContains(
            '[data-testid="stats-top-lists-card-top_departments"]',
            'Destination departments that appear most often among assignments.',
        );
        $this->assertSelectorTextContains(
            '[data-testid="stats-top-lists-card-top_infections"]',
            'Reported or suspected infectious diseases among assignments.',
        );
    }

    public function testLegacyReportQueryRedirectsToDetailRoute(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists?scope=public&period=all&report=top_diagnoses',
        );

        $this->assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('/statistics/top-lists/top_diagnoses', $location);
        $this->assertStringContainsString('scope=public', $location);
        $this->assertStringNotContainsString('report=', $location);
    }

    public function testUnknownTopListReturnsNotFound(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/top-lists/not_a_real_list?scope=public');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testReportsPageIsDisplayedWithTable(): void
    {
        $client = $this->createClientAsRoleUser();

        UserFactory::createOne(['username' => 'stats-report-test']);
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);
        HospitalFactory::createOne(['name' => 'Test Hospital']);
        $import = ImportFactory::createOne(['name' => 'Test Import']);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        OccasionFactory::createOne(['name' => 'Test Occasion']);
        InfectionFactory::createOne(['name' => 'Test Infection']);
        $raw = IndicationRawFactory::createOne(['name' => 'Seeded Report Diagnosis Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Seeded Report Diagnosis']);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('today'),
            'import' => $import,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
        ]);
        $this->rebuildProjectionForImports([(int) $import->getId()]);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_diagnoses?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-explorer-sidebar"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-widget"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-table-card"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-table-card"] .card-header');
        $this->assertSelectorExists('[data-testid="stats-analysis-table-card"] [data-testid="stats-top-lists-ranking-depth"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-limit-trigger"]');
        $this->assertSelectorTextContains('[data-testid="stats-top-lists-limit-trigger"]', 'Top 25 entries');
        $this->assertSelectorTextContains('[data-testid="stats-top-lists-limit-10"]', 'Top 10 entries');
        $this->assertSelectorTextContains('[data-testid="stats-top-lists-limit-all"]', 'All entries');
        $this->assertSelectorExists('[data-testid="stats-top-lists-page-size-trigger"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Rank');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Indication');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Count');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Share');
        $this->assertSelectorExists('[data-testid="stats-top-lists-header-actions"].btn-group');
        $this->assertSelectorExists('[data-testid="stats-top-lists-header-actions"] [data-testid="stats-top-lists-catalog-link"].btn');
        $this->assertSelectorExists('[data-testid="stats-top-lists-header-actions"] [data-testid="stats-top-lists-compare-enable"].btn');
        $this->assertSelectorNotExists('[data-testid="stats-top-lists-compare-enable"].btn-outline-primary');
        $catalogHref = $crawler->filter('[data-testid="stats-top-lists-catalog-link"]')->attr('href');
        $this->assertSame('/explore/indication', $catalogHref);
        $rowLink = $crawler->filter('[data-testid="stats-analysis-table-card"] tbody a');
        $rowHref = $rowLink->attr('href');
        $this->assertNotNull($rowHref);
        $this->assertSame('/explore/indication/'.$normalized->getPublicIdString(), $rowHref);
        $this->assertStringNotContainsString('scope=', $rowHref);
        $this->assertStringNotContainsString('period=', $rowHref);
        $this->assertStringNotContainsString('text-reset', (string) $rowLink->attr('class'));
        $this->assertSelectorExists('[data-testid="stats-analysis-table-card"] [data-testid="stats-top-lists-share-bar"]');
        $shareBarStyle = $crawler->filter('[data-testid="stats-top-lists-share-bar"] .progress-bar')->attr('style');
        $this->assertNotNull($shareBarStyle);
        $this->assertMatchesRegularExpression('/width:\s*100(\.0)?%/', $shareBarStyle);
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"] tbody', '100.0%');
        $firstRowCells = $crawler->filter('[data-testid="stats-analysis-table-card"] tbody tr')->first()->filter('td');
        $this->assertCount(5, $firstRowCells);
        $this->assertStringContainsString('100.0%', $firstRowCells->eq(3)->text());
        $this->assertGreaterThan(0, $firstRowCells->eq(4)->filter('[data-testid="stats-top-lists-share-bar"]')->count());
        $this->assertStringNotContainsString('100.0%', $firstRowCells->eq(4)->text());
    }

    public function testTopDepartmentsReportIsDisplayedWithTable(): void
    {
        $client = $this->createClientAsRoleUser();

        UserFactory::createOne(['username' => 'stats-report-dept-test']);
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);
        HospitalFactory::createOne(['name' => 'Test Hospital']);
        $import = ImportFactory::createOne(['name' => 'Test Import']);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        $department = DepartmentFactory::createOne(['name' => 'Seeded Report Department']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        OccasionFactory::createOne(['name' => 'Test Occasion']);
        InfectionFactory::createOne(['name' => 'Test Infection']);
        $raw = IndicationRawFactory::createOne(['name' => 'Seeded Report Diagnosis Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Seeded Report Diagnosis']);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('today'),
            'import' => $import,
            'department' => $department,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
        ]);
        $this->rebuildProjectionForImports([(int) $import->getId()]);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_departments?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-top-lists-widget"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Department');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Seeded Report Department');
        $this->assertSelectorExists('[data-testid="stats-top-lists-catalog-link"]');
        $this->assertSame(
            '/explore/department',
            $crawler->filter('[data-testid="stats-top-lists-catalog-link"]')->attr('href'),
        );
        $rowHref = $crawler->filter('[data-testid="stats-analysis-table-card"] tbody a')->attr('href');
        $this->assertNotNull($rowHref);
        $this->assertSame('/explore/department/'.$department->getPublicIdString(), $rowHref);
        $this->assertStringNotContainsString('scope=', $rowHref);
        $this->assertStringNotContainsString('period=', $rowHref);
    }

    public function testPaginationLinksIncludeReportPathParameter(): void
    {
        $client = $this->createClientAsRoleUser();

        UserFactory::createOne(['username' => 'stats-top-lists-pagination']);
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);
        HospitalFactory::createOne(['name' => 'Test Hospital']);
        $import = ImportFactory::createOne(['name' => 'Pagination Import']);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        OccasionFactory::createOne(['name' => 'Test Occasion']);
        InfectionFactory::createOne(['name' => 'Test Infection']);
        $raw = IndicationRawFactory::createOne(['name' => 'Pagination Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Pagination Diagnosis']);

        $importId = (int) $import->getId();
        for ($i = 1; $i <= 26; ++$i) {
            AllocationFactory::createOne([
                'createdAt' => new \DateTimeImmutable('today'),
                'import' => $import,
                'department' => DepartmentFactory::createOne(['name' => sprintf('Paged Department %02d', $i)]),
                'indicationRaw' => $raw,
                'indicationNormalized' => $normalized,
            ]);
        }
        $this->rebuildProjectionForImports([$importId]);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_departments?scope=public&period=all&limit=50',
        );

        $this->assertResponseIsSuccessful();
        $next = $crawler->filter('.pagination a.page-link[href*="page=2"]');
        self::assertGreaterThan(0, $next->count());
        $href = (string) $next->first()->attr('href');
        self::assertStringContainsString('/statistics/top-lists/top_departments', $href);
        self::assertStringContainsString('limit=50', $href);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('newTopReportCases')]
    public function testAllNewTopReportsAreRenderedWithExpectedColumnAndValue(
        string $reportKey,
        string $expectedColumnLabel,
        string $expectedValue,
    ): void {
        $client = $this->createClientAsRoleUser();

        UserFactory::createOne(['username' => 'stats-report-all-new-test']);
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);
        HospitalFactory::createOne(['name' => 'Test Hospital']);
        $import = ImportFactory::createOne(['name' => 'Test Import']);
        $speciality = SpecialityFactory::createOne(['name' => 'Seeded Report Speciality']);
        $department = DepartmentFactory::createOne(['name' => 'Seeded Report Department']);
        $assignment = AssignmentFactory::createOne(['name' => 'Seeded Report Assignment']);
        $occasion = OccasionFactory::createOne(['name' => 'Seeded Report Occasion']);
        $infection = InfectionFactory::createOne(['name' => 'Seeded Report Infection']);
        $raw = IndicationRawFactory::createOne(['name' => 'Seeded Report Diagnosis Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Seeded Report Diagnosis']);
        $secondaryNormalized = IndicationNormalizedFactory::createOne(['name' => 'Seeded Report Secondary Diagnosis']);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('today'),
            'import' => $import,
            'speciality' => $speciality,
            'department' => $department,
            'assignment' => $assignment,
            'occasion' => $occasion,
            'infection' => $infection,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
            'secondaryIndicationNormalized' => $secondaryNormalized,
        ]);
        $this->rebuildProjectionForImports([(int) $import->getId()]);

        $client->request(
            Request::METHOD_GET,
            sprintf('/statistics/top-lists/%s?scope=public&period=all', $reportKey),
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-top-lists-widget"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', $expectedColumnLabel);
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', $expectedValue);
    }

    /**
     * @return iterable<string, array{0:string,1:string,2:string}>
     */
    public static function newTopReportCases(): iterable
    {
        yield 'top_departments' => ['top_departments', 'Department', 'Seeded Report Department'];
        yield 'top_assignments' => ['top_assignments', 'Assignment type', 'Seeded Report Assignment'];
        yield 'top_infections' => ['top_infections', 'Infection', 'Seeded Report Infection'];
        yield 'top_secondary_diagnoses' => ['top_secondary_diagnoses', 'Secondary indication', 'Seeded Report Secondary Diagnosis'];
        yield 'top_specialities' => ['top_specialities', 'Speciality', 'Seeded Report Speciality'];
        yield 'top_occasions' => ['top_occasions', 'Occasion', 'Seeded Report Occasion'];
    }

    public function testSecondaryDiagnosesExcludeMissingValuesFromCountsAndShares(): void
    {
        $client = $this->createClientAsRoleUser();

        UserFactory::createOne(['username' => 'stats-secondary-null-test']);
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);
        HospitalFactory::createOne(['name' => 'Test Hospital']);
        $import = ImportFactory::createOne(['name' => 'Test Import']);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        OccasionFactory::createOne(['name' => 'Test Occasion']);
        $raw = IndicationRawFactory::createOne(['name' => 'Primary Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Primary Indication']);
        $secondary = IndicationNormalizedFactory::createOne(['name' => 'Present Secondary']);
        $allocationDefaults = [
            'createdAt' => new \DateTimeImmutable('today'),
            'import' => $import,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
        ];
        AllocationFactory::createOne($allocationDefaults);
        AllocationFactory::createOne($allocationDefaults);
        AllocationFactory::createOne($allocationDefaults + ['secondaryIndicationNormalized' => $secondary]);
        $this->rebuildProjectionForImports([(int) $import->getId()]);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_secondary_diagnoses?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $tableText = $crawler->filter('[data-testid="stats-analysis-table-card"]')->text();
        self::assertStringNotContainsString('Unknown', $tableText);
        self::assertStringNotContainsString('Unbekannt', $tableText);

        $rows = $crawler->filter('[data-testid="stats-analysis-table-card"] tbody tr');
        self::assertCount(1, $rows);
        $cells = $rows->eq(0)->filter('td');
        self::assertSame('Present Secondary', trim($cells->eq(1)->text()));
        self::assertSame('1', trim($cells->eq(2)->text()));
        self::assertSame('100.0%', trim($cells->eq(3)->text()));
    }

    public function testInfectionsExcludeMissingValuesFromCountsAndShares(): void
    {
        $client = $this->createClientAsRoleUser();

        UserFactory::createOne(['username' => 'stats-infection-null-test']);
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);
        HospitalFactory::createOne(['name' => 'Test Hospital']);
        $import = ImportFactory::createOne(['name' => 'Test Import']);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        OccasionFactory::createOne(['name' => 'Test Occasion']);
        $raw = IndicationRawFactory::createOne(['name' => 'Primary Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Primary Indication']);
        $infection = InfectionFactory::createOne(['name' => 'Present Infection']);
        $allocationDefaults = [
            'createdAt' => new \DateTimeImmutable('today'),
            'import' => $import,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
        ];
        AllocationFactory::createOne($allocationDefaults + ['infection' => null]);
        AllocationFactory::createOne($allocationDefaults + ['infection' => null]);
        AllocationFactory::createOne($allocationDefaults + ['infection' => $infection]);
        $this->rebuildProjectionForImports([(int) $import->getId()]);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_infections?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $tableText = $crawler->filter('[data-testid="stats-analysis-table-card"]')->text();
        self::assertStringNotContainsString('Unknown', $tableText);
        self::assertStringNotContainsString('Unbekannt', $tableText);

        $rows = $crawler->filter('[data-testid="stats-analysis-table-card"] tbody tr');
        self::assertCount(1, $rows);
        $cells = $rows->eq(0)->filter('td');
        self::assertSame('Present Infection', trim($cells->eq(1)->text()));
        self::assertSame('1', trim($cells->eq(2)->text()));
        self::assertSame('100.0%', trim($cells->eq(3)->text()));
    }

    public function testLimitParameterTenIsAccepted(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_diagnoses?scope=public&period=all&limit=10',
        );

        $this->assertResponseIsSuccessful();
        $link = $crawler->filter('[data-testid="stats-top-lists-limit-10"]')->link();
        $this->assertStringContainsString('limit=10', $link->getUri());
    }

    public function testLimitParameterHundredAndAllAreAccepted(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_diagnoses?scope=public&period=all&limit=100',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-top-lists-limit-100"].active');
        $allLink = $crawler->filter('[data-testid="stats-top-lists-limit-all"]')->link();
        $this->assertStringContainsString('limit=all', $allLink->getUri());

        $client->request(Request::METHOD_GET, '/statistics/top-lists/top_diagnoses?scope=public&period=all&limit=all');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-top-lists-limit-all"].active');
        $this->assertSelectorExists('[data-testid="stats-top-lists-compare-enable"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-compare-enable"][data-bs-toggle="modal"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-comparison-modal-b"]');
        $this->assertSelectorNotExists('[data-testid="stats-top-lists-compare-enable"][href]');
    }

    public function testCompareModeRendersSelectionCardAndComparisonTable(): void
    {
        $client = $this->createClientAsRoleUser();

        UserFactory::createOne(['username' => 'stats-top-lists-compare']);
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);
        HospitalFactory::createOne(['name' => 'Test Hospital']);
        $import = ImportFactory::createOne(['name' => 'Compare Import']);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        OccasionFactory::createOne(['name' => 'Test Occasion']);
        InfectionFactory::createOne(['name' => 'Test Infection']);
        $raw = IndicationRawFactory::createOne(['name' => 'Compare Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Compare Diagnosis']);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('today'),
            'import' => $import,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
        ]);
        $this->rebuildProjectionForImports([(int) $import->getId()]);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_diagnoses?scope=public&period=year&year=2021&compare=1&comparison_scope=public&comparison_period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-top-lists-comparison-workspace"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-comparison-workspace"] [data-testid="stats-top-lists-compare-selection"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-comparison-workspace"] [data-testid="stats-top-lists-comparison-table"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-comparison-table-a"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-comparison-table-b"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-comparison-workspace"] [data-testid="stats-top-lists-ranking-depth"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-comparison-workspace"] [data-testid="stats-top-lists-page-size-trigger"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-table-card"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-compare-a-edit"][data-bs-target="#stats-top-lists-comparison-modal-a"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-compare-b-edit"][data-bs-target="#stats-top-lists-comparison-modal-b"]');
        $this->assertSelectorTextContains('[data-testid="stats-top-lists-compare-a-edit"]', 'Edit comparison A');
        $this->assertSelectorTextContains('[data-testid="stats-top-lists-compare-b-edit"]', 'Edit comparison B');
        $this->assertSelectorExists('[data-testid="stats-top-lists-comparison-modal-a"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-comparison-modal-b"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-compare-disable"].btn-outline-danger');
        $this->assertSelectorNotExists('[data-testid="stats-top-lists-compare-edit"]');
        $this->assertSelectorNotExists('[data-testid="stats-top-lists-compare-actions"]');
        $this->assertSelectorNotExists('[data-testid="stats-top-lists-compare-enable"]');
        $this->assertSelectorNotExists('[data-testid="stats-explorer-sidebar"]');
        $this->assertSelectorNotExists('[data-testid="stats-scope"]');
        $this->assertSelectorNotExists('[data-testid="stats-scope-primary"]');
        $this->assertSelectorNotExists('[data-testid="stats-period-primary"]');
        $this->assertSelectorNotExists('[data-testid="stats-period-navigation"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-ranking-depth"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-page-size-trigger"]');

        $toolbarHtml = $crawler->filter('.page-header .btn-list')->html();
        $filterPos = strpos($toolbarHtml, 'statistics-filters-drawer-trigger');
        $disablePos = strpos($toolbarHtml, 'stats-top-lists-compare-disable');
        self::assertNotFalse($filterPos);
        self::assertNotFalse($disablePos);
        self::assertLessThan($disablePos, $filterPos);

        $this->assertSelectorExists('[data-testid="stats-top-lists-compare-swap"]');
        $this->assertSelectorNotExists('[data-testid="stats-top-lists-compare-swap-header"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-compare-selection"] [data-testid="stats-top-lists-compare-swap"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-compare-continue-a"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-compare-continue-b"]');
        $swapQuery = $this->queryParamsFromHref($crawler->filter('[data-testid="stats-top-lists-compare-swap"]')->attr('href'));
        self::assertSame('1', $swapQuery['compare'] ?? null);
        self::assertSame('public', $swapQuery['scope'] ?? null);
        self::assertSame('all', $swapQuery['period'] ?? null);
        self::assertSame('public', $swapQuery['comparison_scope'] ?? null);
        self::assertSame('year', $swapQuery['comparison_period'] ?? null);
        self::assertSame('2021', (string) ($swapQuery['comparison_year'] ?? ''));
        $continueAQuery = $this->queryParamsFromHref($crawler->filter('[data-testid="stats-top-lists-compare-continue-a"]')->attr('href'));
        self::assertArrayNotHasKey('compare', $continueAQuery);
        self::assertSame('public', $continueAQuery['scope'] ?? null);
        self::assertSame('year', $continueAQuery['period'] ?? null);
        self::assertSame('2021', (string) ($continueAQuery['year'] ?? ''));
        self::assertArrayNotHasKey('comparison_scope', $continueAQuery);
        $continueBQuery = $this->queryParamsFromHref($crawler->filter('[data-testid="stats-top-lists-compare-continue-b"]')->attr('href'));
        self::assertArrayNotHasKey('compare', $continueBQuery);
        self::assertSame('public', $continueBQuery['scope'] ?? null);
        self::assertSame('all', $continueBQuery['period'] ?? null);
        self::assertArrayNotHasKey('comparison_scope', $continueBQuery);
        self::assertArrayNotHasKey('comparison_period', $continueBQuery);
    }

    public function testCompareDiagnosisRowsLinkToCatalogWithoutScopeAndPeriod(): void
    {
        $client = $this->createClientAsRoleUser();

        UserFactory::createOne(['username' => 'stats-top-lists-compare-links']);
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);
        HospitalFactory::createOne(['name' => 'Test Hospital']);
        $import = ImportFactory::createOne(['name' => 'Compare Links Import']);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        OccasionFactory::createOne(['name' => 'Test Occasion']);
        InfectionFactory::createOne(['name' => 'Test Infection']);
        $raw = IndicationRawFactory::createOne(['name' => 'Compare Links Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Compare Links Diagnosis']);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('today'),
            'import' => $import,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
        ]);
        $this->rebuildProjectionForImports([(int) $import->getId()]);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_diagnoses?scope=public&period=all&compare=1',
        );

        $this->assertResponseIsSuccessful();
        $compareLink = $crawler->filter('[data-testid="stats-top-lists-comparison-table-a"] tbody a');
        $compareHref = $compareLink->attr('href');
        $this->assertNotNull($compareHref);
        $this->assertSame('/explore/indication/'.$normalized->getPublicIdString(), $compareHref);
        $this->assertStringNotContainsString('scope=', $compareHref);
        $this->assertStringNotContainsString('period=', $compareHref);
        $this->assertStringNotContainsString('text-reset', (string) $compareLink->attr('class'));
        $this->assertSelectorNotExists('[data-testid="stats-top-lists-share-bar"]');
    }

    public function testInvalidLimitFallsBackToTwentyFive(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_diagnoses?scope=public&period=all&limit=invalid',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-top-lists-limit-25"].active');
    }

    public function testPageSizeParameterIsAccepted(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_diagnoses?scope=public&period=all&limit=10&per_page=50',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-top-lists-limit-10"].active');
        $this->assertSelectorExists('[data-testid="stats-top-lists-page-size-50"].active');
        $link = $crawler->filter('[data-testid="stats-top-lists-page-size-100"]')->link();
        $this->assertStringContainsString('per_page=100', $link->getUri());
        $this->assertStringContainsString('limit=10', $link->getUri());
    }

    public function testInvalidPageSizeFallsBackToTwentyFive(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_diagnoses?scope=public&period=all&per_page=invalid',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-top-lists-page-size-25"].active');
    }

    public function testReportsPageAcceptsScopeAndPeriodParameters(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_diagnoses?scope=public&period=all_time',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-heading-title"]');
        $this->assertSelectorExists('[data-testid="stats-heading-subtitle"]');
        $this->assertSelectorExists('[data-testid="stats-scope"]');
        $this->assertSelectorExists('[data-testid="stats-period-primary"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-compare-enable"][data-bs-toggle="modal"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-compare-enable"][data-bs-target="#stats-top-lists-comparison-modal-b"]');
        $this->assertSelectorExists('[data-testid="stats-top-lists-comparison-modal-b"]');
        $this->assertSelectorNotExists('[data-testid="stats-top-lists-compare-enable"][href]');
        $this->assertSelectorNotExists('[data-testid="stats-top-lists-comparison-modal-a"]');

        $toolbarHtml = $crawler->filter('.page-header .btn-list')->html();
        $comparePos = strpos($toolbarHtml, 'stats-top-lists-compare-enable');
        $filterPos = strpos($toolbarHtml, 'statistics-filters-drawer-trigger');
        self::assertNotFalse($comparePos);
        self::assertNotFalse($filterPos);
        self::assertLessThan($filterPos, $comparePos);
    }

    public function testReportsShowsPeriodNavigationWithYearPeriod(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_diagnoses?scope=public&period=year&year=2021',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-period-navigation"]');
        $this->assertSelectorExists('[data-testid="stats-period-primary"]');
        $previousHref = $crawler->filter('[data-testid="stats-period-nav-previous"] a.page-link[href]')->attr('href');
        $this->assertStringContainsString('/statistics/top-lists/top_diagnoses', $previousHref);
        $this->assertStringContainsString('year=2020', $previousHref);
    }

    public function testReportsLast12MonthsHidesPeriodNavigation(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_diagnoses?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-period-navigation"]');
        $this->assertSelectorExists('[data-testid="stats-period-primary"]');
    }

    public function testReportsFilterDrawerRendersAccordionAndTrigger(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_diagnoses?scope=public&period=all&gender=2&age_group=30_39',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="statistics-filters-drawer-trigger"]');
        $this->assertSelectorExists('[data-testid="statistics-filters-clear"]');
        $this->assertSelectorExists('[data-testid="statistics-filters-active"]');
        $this->assertSelectorTextContains('[data-testid="statistics-filters-active"]', 'Female');
        $this->assertSelectorExists('#statistics-filter-drawer-accordion');
        $this->assertSelectorNotExists('[data-testid="statistics-filter-section-hospital"]');
        $this->assertSelectorNotExists('[data-testid="statistics-filter-section-geography"]');
        $this->assertSelectorExists('[data-testid="statistics-filter-section-allocation"]');
        $this->assertSelectorExists('[data-testid="statistics-filter-section-demographics"]');
        $this->assertSelectorExists('#statistics-filter-demographics-panel.show');
        $this->assertSelectorExists('[data-testid="statistics-filter-age-group"]');
        $this->assertSelectorExists('[data-testid="statistics-filter-age-group"] option[value="under_18"]');
        $this->assertSelectorExists('[data-testid="statistics-filter-age-group"] option[value="over_80"]');
        $this->assertSelectorNotExists('[data-testid="statistics-filter-age-group"] option[value="unknown"]');
        $this->assertSelectorExists('[data-testid="statistics-filter-department"]');
        $this->assertSelectorExists('[data-testid="statistics-filter-requires-resus"]');
        $this->assertSelectorNotExists('[data-testid="statistics-filter-feature"]');
        $this->assertSelectorNotExists('#statistics-filter-form input.form-control');
        $this->assertSelectorExists('#statistics-filters-drawer .offcanvas-footer');
        $this->assertSelectorExists('[data-testid="statistics-filters-cancel"]');
        $this->assertSelectorExists('[data-testid="statistics-filters-apply"]');
        $this->assertSelectorExists('[data-testid="statistics-filters-reset"].btn-outline-secondary');
    }

    public function testReportsFilterDrawerShowsResetWithoutActiveFilters(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_diagnoses?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#statistics-filters-drawer .offcanvas-footer');
        $this->assertSelectorExists('[data-testid="statistics-filters-reset"].btn-outline-secondary');
    }

    public function testReportsDrawerUrgencyFilterNarrowsTableResults(): void
    {
        $client = $this->createClientAsRoleUser();

        UserFactory::createOne(['username' => 'stats-report-urgency-filter']);
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);
        HospitalFactory::createOne(['name' => 'Test Hospital']);
        $import = ImportFactory::createOne(['name' => 'Urgency Filter Import']);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        OccasionFactory::createOne(['name' => 'Test Occasion']);
        InfectionFactory::createOne(['name' => 'Test Infection']);
        $raw = IndicationRawFactory::createOne(['name' => 'Urgency Filter Raw']);
        $emergency = IndicationNormalizedFactory::createOne(['name' => 'Urgency Filter Emergency']);
        $inpatient = IndicationNormalizedFactory::createOne(['name' => 'Urgency Filter Inpatient']);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('today'),
            'import' => $import,
            'indicationRaw' => $raw,
            'indicationNormalized' => $emergency,
            'urgency' => AllocationUrgency::EMERGENCY,
        ]);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('today'),
            'import' => $import,
            'indicationRaw' => $raw,
            'indicationNormalized' => $inpatient,
            'urgency' => AllocationUrgency::INPATIENT,
        ]);
        $this->rebuildProjectionForImports([(int) $import->getId()]);

        $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_diagnoses?scope=public&period=all&urgency=1',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Urgency Filter Emergency');
        $this->assertSelectorTextNotContains('[data-testid="stats-analysis-table-card"]', 'Urgency Filter Inpatient');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', '100.0%');
    }

    public function testCompareSwapExchangesSidesAndKeepsCompareMode(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedCompareDiagnosisFixtures('stats-top-lists-compare-swap');

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_diagnoses?scope=public&period=year&year=2021&compare=1&comparison_scope=public&comparison_period=all',
        );

        $this->assertResponseIsSuccessful();
        $headingA = trim($crawler->filter('[data-testid="stats-top-lists-compare-a"]')->text());
        $headingB = trim($crawler->filter('[data-testid="stats-top-lists-compare-b"]')->text());
        $periodA = trim($crawler->filter('[data-testid="stats-top-lists-compare-selection-period-a"]')->text());
        $periodB = trim($crawler->filter('[data-testid="stats-top-lists-compare-selection-period-b"]')->text());
        self::assertNotSame($periodA, $periodB);

        $swapHref = $crawler->filter('[data-testid="stats-top-lists-compare-swap"]')->attr('href');
        $this->assertNotNull($swapHref);
        $crawler = $client->request(Request::METHOD_GET, $swapHref);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-top-lists-comparison-workspace"]');
        $this->assertSelectorTextContains('[data-testid="stats-top-lists-compare-a"]', $headingB);
        $this->assertSelectorTextContains('[data-testid="stats-top-lists-compare-b"]', $headingA);
        $this->assertSelectorTextContains('[data-testid="stats-top-lists-compare-selection-period-a"]', $periodB);
        $this->assertSelectorTextContains('[data-testid="stats-top-lists-compare-selection-period-b"]', $periodA);
        $swappedQuery = $this->queryParamsFromHref($client->getRequest()->getRequestUri());
        self::assertSame('1', $swappedQuery['compare'] ?? null);
    }

    public function testCompareContinueWithAKeepsPrimaryAndLeavesCompare(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedCompareDiagnosisFixtures('stats-top-lists-compare-continue-a');

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_diagnoses?scope=public&period=year&year=2021&compare=1&comparison_scope=public&comparison_period=all',
        );

        $continueHref = $crawler->filter('[data-testid="stats-top-lists-compare-continue-a"]')->attr('href');
        $this->assertNotNull($continueHref);
        $client->request(Request::METHOD_GET, $continueHref);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-top-lists-comparison-workspace"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-table-card"]');
        $query = $this->queryParamsFromHref($client->getRequest()->getRequestUri());
        self::assertArrayNotHasKey('compare', $query);
        self::assertSame('public', $query['scope'] ?? null);
        self::assertSame('year', $query['period'] ?? null);
        self::assertSame('2021', (string) ($query['year'] ?? ''));
        self::assertArrayNotHasKey('comparison_scope', $query);
    }

    public function testCompareContinueWithBPromotesComparisonAndLeavesCompare(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedCompareDiagnosisFixtures('stats-top-lists-compare-continue-b');

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_diagnoses?scope=public&period=year&year=2021&compare=1&comparison_scope=public&comparison_period=all',
        );

        $continueHref = $crawler->filter('[data-testid="stats-top-lists-compare-continue-b"]')->attr('href');
        $this->assertNotNull($continueHref);
        $client->request(Request::METHOD_GET, $continueHref);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-top-lists-comparison-workspace"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-table-card"]');
        $query = $this->queryParamsFromHref($client->getRequest()->getRequestUri());
        self::assertArrayNotHasKey('compare', $query);
        self::assertSame('public', $query['scope'] ?? null);
        self::assertSame('all', $query['period'] ?? null);
        self::assertArrayNotHasKey('comparison_scope', $query);
        self::assertArrayNotHasKey('comparison_period', $query);
    }

    private function seedCompareDiagnosisFixtures(string $username): void
    {
        UserFactory::createOne(['username' => $username]);
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);
        HospitalFactory::createOne(['name' => 'Test Hospital']);
        $import = ImportFactory::createOne(['name' => $username.' Import']);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        OccasionFactory::createOne(['name' => 'Test Occasion']);
        InfectionFactory::createOne(['name' => 'Test Infection']);
        $raw = IndicationRawFactory::createOne(['name' => $username.' Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => $username.' Diagnosis']);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('today'),
            'import' => $import,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
        ]);
        $this->rebuildProjectionForImports([(int) $import->getId()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function queryParamsFromHref(?string $href): array
    {
        $this->assertNotNull($href);
        $query = parse_url($href, PHP_URL_QUERY);
        if (!\is_string($query) || '' === $query) {
            return [];
        }

        $params = [];
        parse_str($query, $params);

        return $params;
    }
}
