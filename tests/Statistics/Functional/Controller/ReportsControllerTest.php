<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

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
class ReportsControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use RefreshesStatisticsFunctionalDataTrait;

    use Factories;

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

        $client->request(
            Request::METHOD_GET,
            '/statistics/reports?scope=public&period=all&report=top_diagnoses',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-explorer-sidebar"]');
        $this->assertSelectorExists('[data-testid="stats-reports-widget"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-table-card"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-table-card"] .card-header');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Rank');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Indication');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Count');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Share');
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

        $client->request(
            Request::METHOD_GET,
            '/statistics/reports?scope=public&period=all&report=top_departments',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-reports-widget"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Department');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Seeded Report Department');
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
            sprintf('/statistics/reports?scope=public&period=all&report=%s', $reportKey),
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-reports-widget"]');
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

    public function testLimitParameterTenIsAccepted(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/reports?scope=public&period=all&limit=10',
        );

        $this->assertResponseIsSuccessful();
        $link = $crawler->filter('[data-testid="stats-reports-limit-10"]')->link();
        $this->assertStringContainsString('limit=10', $link->getUri());
    }

    public function testInvalidLimitFallsBackToTwentyFive(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/reports?scope=public&period=all&limit=invalid',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-reports-limit-25"].active');
    }

    public function testReportsPageAcceptsScopeAndPeriodParameters(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/reports?scope=public&period=all_time',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-heading-title"]');
        $this->assertSelectorExists('[data-testid="stats-heading-subtitle"]');
    }

    public function testReportsShowsPeriodNavigationWithYearPeriod(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/reports?scope=public&period=year&year=2021',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-period-navigation"]');
        $this->assertSelectorExists('[data-testid="stats-period-primary"]');
        $previousHref = $crawler->filter('[data-testid="stats-period-nav-previous"] a.page-link[href]')->attr('href');
        $this->assertStringContainsString('/statistics/reports', $previousHref);
        $this->assertStringContainsString('year=2020', $previousHref);
    }

    public function testReportsLast12MonthsHidesPeriodNavigation(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/reports?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-period-navigation"]');
        $this->assertSelectorExists('[data-testid="stats-period-primary"]');
    }
}
