<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\Request;

final class AnalysisContextHeaderTest extends DashboardControllerTestCase
{
    public function testDashboardRendersAnalysisContextTriggerAndModal(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=year&year=2024',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-context-trigger"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-modal"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-context-location"]', 'All assignments');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-context-period-summary"]', '2024');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-form"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-scope-group"] option[value="public"][selected]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-period"] option[value="year"][selected]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-year"] option[value="2024"][selected]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-apply"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-cancel"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-reset"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-context-reset"]', 'Reset');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-location"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-period-summary"]');
        $this->assertSelectorNotExists('[data-testid="stats-scope-primary"]');
        $this->assertSelectorNotExists('[data-testid="stats-period-primary"]');

        $formAction = $crawler->filter('[data-testid="stats-analysis-context-form"]')->attr('action');
        $this->assertNotNull($formAction);
        $this->assertStringContainsString('/statistics', $formAction);
    }

    public function testCaseFlowPreservesDrawerFiltersInAnalysisContextForm(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/case-flow?scope=public&period=all&gender=2',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="statistics-filters-drawer-trigger"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-form"] input[name="gender"][value="2"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-form"] input[name="scope"][value="public"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-period"] option[value="all"][selected]');

        $hiddenNames = $crawler->filter('[data-testid="stats-analysis-context-form"] input[type="hidden"]')->each(
            static fn ($node): string => (string) $node->attr('name'),
        );
        self::assertContains('gender', $hiddenNames);
        self::assertNotContains('period', $hiddenNames);
    }

    public function testAnalysisContextFormOmitsInactivePeriodFieldsAndKeepsFilters(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/case-flow?scope=public&period=all&gender=2',
        );

        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('[data-testid="stats-analysis-context-form"]')->form();
        $values = $form->getPhpValues();
        self::assertSame('public', $values['scope'] ?? null);
        self::assertSame('all', $values['period'] ?? null);
        self::assertSame('2', $values['gender'] ?? null);
        self::assertArrayNotHasKey('year', $values);
        self::assertArrayNotHasKey('month', $values);
        self::assertArrayNotHasKey('quarter', $values);
    }

    public function testAnalysisContextFormSubmitAppliesPeriod(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=year&year=2024',
        );

        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('[data-testid="stats-analysis-context-form"]')->form();
        $periodField = $form->get('period');
        self::assertInstanceOf(ChoiceFormField::class, $periodField);
        $periodField->select('all_time');
        $client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-context-period"] option[value="all_time"][selected]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-context-period-summary"]', 'All time');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-form"] input[name="scope"][value="public"]');
    }

    public function testReportsIndexRendersFullAnalysisContext(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/reports?scope=public&period=year&year=2024',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-context-trigger"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-period"] option[value="year"][selected]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-year"] option[value="2024"][selected]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-period-summary"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-context-location"]', 'All assignments');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-context-period-summary"]', '2024');
    }

    public function testTopListsIndexRendersFullAnalysisContext(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists?scope=public&period=year&year=2024',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-context-trigger"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-modal"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-period"] option[value="year"][selected]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-year"] option[value="2024"][selected]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-context-location"]', 'All assignments');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-context-period-summary"]', '2024');

        $href = $crawler->filter('[data-testid="stats-top-lists-card-top_diagnoses"]')->attr('href');
        $this->assertStringContainsString('/statistics/top-lists/top_diagnoses', (string) $href);
        $this->assertStringContainsString('scope=public', (string) $href);
        $this->assertStringContainsString('period=year', (string) $href);
        $this->assertStringContainsString('year=2024', (string) $href);
    }

    public function testReportsIndexApplyKeepsPeriodOnCatalogCards(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/reports?scope=public&period=year&year=2024',
        );

        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('[data-testid="stats-analysis-context-form"]')->form();
        $periodField = $form->get('period');
        self::assertInstanceOf(ChoiceFormField::class, $periodField);
        $periodField->select('all_time');
        $crawler = $client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-reports-index"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-period"] option[value="all_time"][selected]');
        $href = $crawler->filter('[data-testid="stats-reports-card-monthly"]')->attr('href');
        $this->assertStringContainsString('/statistics/reports/monthly', (string) $href);
        $this->assertStringContainsString('period=all_time', (string) $href);
        $this->assertStringContainsString('scope=public', (string) $href);
    }

    public function testTopListsIndexApplyKeepsPeriodOnCatalogCards(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists?scope=public&period=year&year=2024',
        );

        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('[data-testid="stats-analysis-context-form"]')->form();
        $periodField = $form->get('period');
        self::assertInstanceOf(ChoiceFormField::class, $periodField);
        $periodField->select('all_time');
        $crawler = $client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-top-lists-index"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-period"] option[value="all_time"][selected]');
        $href = $crawler->filter('[data-testid="stats-top-lists-card-top_diagnoses"]')->attr('href');
        $this->assertStringContainsString('/statistics/top-lists/top_diagnoses', (string) $href);
        $this->assertStringContainsString('period=all_time', (string) $href);
        $this->assertStringContainsString('scope=public', (string) $href);
    }

    public function testInsightsOverviewRendersAnalysisContext(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/insights?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-context-trigger"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-modal"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-context-location"]', 'All assignments');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-period"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-period-summary"]');
    }

    public function testClosedDepartmentAssignmentsRendersAnalysisContext(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/closed-department-assignments?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-context-trigger"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-modal"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-period"] option[value="all"][selected]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-context-location"]', 'All assignments');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-context-period-summary"]', 'Last 12 months');
    }

    public function testHospitalLocationTriggerOmitsNamedLinePrefix(): void
    {
        $client = self::createClient();
        $user = UserFactory::new()->asAdmin()->create();
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'AnalysisContextState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'AnalysisContextDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Context Klinik',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'owner' => $user,
            'createdBy' => $user,
        ]);
        HospitalFactory::createOne([
            'name' => 'Other Klinik',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'owner' => $user,
            'createdBy' => $user,
        ]);

        $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=hospital&hospital='.$hospital->getId().'&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-analysis-context-location"]', 'Context Klinik');
        $location = $client->getCrawler()->filter('[data-testid="stats-analysis-context-location"]')->text();
        self::assertStringNotContainsString('Hospital:', $location);
        $this->assertSelectorExists('[data-testid="stats-analysis-context-hospital"] option[value="'.$hospital->getId().'"][selected]');
    }

    public function testAnalysisContextFormSubmitAppliesMonthPeriod(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=month&year=2024&month=3',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-context-period"] option[value="month"][selected]');
        $form = $crawler->filter('[data-testid="stats-analysis-context-form"]')->form();
        $monthField = $form->get('month');
        self::assertInstanceOf(ChoiceFormField::class, $monthField);
        $monthField->select('4');
        $client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-context-period"] option[value="month"][selected]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-year"] option[value="2024"][selected]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-month"] option[value="4"][selected]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-form"] input[name="scope"][value="public"]');
    }
}
