<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewLoader;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Tests\Statistics\Support\SeedsExplorerSystemViewsTrait;
use App\User\Domain\Factory\UserFactory;

final class AnalysisExplorerShellHappyPathTest extends AnalysisExplorerShellTestCase
{
    use SeedsExplorerSystemViewsTrait;

    public function testMountRendersNaturalLanguageSummary(): void
    {
        $testComponent = $this->createShellComponent();
        $render = $testComponent->render();

        $summary = $render->crawler()->filter('[data-testid="stats-analysis-explorer-summary"]');
        self::assertGreaterThan(0, $summary->count());
        self::assertStringContainsString('Allocations', $summary->text());
        self::assertStringContainsString('monthly', $summary->text());
    }

    public function testMountWithSavedGroupedBarConfig(): void
    {
        self::bootKernel();
        $this->seedExplorerSystemViews();
        $loader = self::getContainer()->get(SavedExplorerViewLoader::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
        $result = $loader->load('gender-over-time', $filter, null);

        $user = UserFactory::createOne(['username' => 'explorer-saved-'.bin2hex(random_bytes(4))]);
        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $result->state,
            'locale' => 'en',
        ])->actingAs($user);

        $testComponent->render();

        self::assertSame('grouped_bar', $testComponent->component()->appliedConfigState['presentation']['chartType'] ?? null);
        self::assertSame('time', $testComponent->component()->appliedConfigState['query']['rows']['dimension'] ?? null);
        self::assertSame('gender', $testComponent->component()->appliedConfigState['query']['columns']['dimension'] ?? null);
    }

    public function testOpenEditShowsPercentCheckboxForGenderOverTime(): void
    {
        self::bootKernel();
        $this->seedExplorerSystemViews();
        $loader = self::getContainer()->get(SavedExplorerViewLoader::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
        $result = $loader->load('gender-over-time', $filter, null);

        $user = UserFactory::createOne(['username' => 'explorer-percent-'.bin2hex(random_bytes(4))]);
        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $result->state,
            'locale' => 'en',
        ])->actingAs($user);

        $testComponent->render();
        $testComponent->call('openEdit');

        $render = $testComponent->render();
        $percentField = $render->crawler()->filter('[data-testid="stats-analysis-explorer-show-percent-field"]');
        self::assertGreaterThan(0, $percentField->count());
        self::assertSame(1, substr_count($percentField->text(), 'Show shares'));
    }

    public function testOpenEditKeepsLibraryBreadcrumb(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $render = $testComponent->render();
        self::assertCount(0, $render->crawler()->filter('[data-testid="stats-analysis-explorer-library-link"]'));
        self::assertGreaterThan(
            0,
            $render->crawler()->filter('a[href="/statistics/analysis/library"]')->count(),
        );
    }

    public function testOpenEditShowsMatrixStructurePreview(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $render = $testComponent->render();
        self::assertGreaterThan(
            0,
            $render->crawler()->filter('[data-testid="stats-analysis-explorer-structure-preview"]')->count(),
        );
        self::assertGreaterThan(
            0,
            $render->crawler()->filter('[data-testid="stats-analysis-explorer-structure-row"]')->count(),
        );
        self::assertGreaterThan(
            0,
            $render->crawler()->filter('[data-testid="stats-analysis-explorer-structure-metric"]')->count(),
        );
    }

    public function testOpenEditGroupsRowDimensionChoices(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $render = $testComponent->render();
        $rowDimensionSelect = $render->crawler()->filter('select[name*="rowDimension"]');
        self::assertGreaterThan(0, $rowDimensionSelect->count());
        self::assertGreaterThan(
            0,
            $rowDimensionSelect->filter('optgroup[label="Time and calendar"] option')->count(),
        );
        self::assertGreaterThan(
            0,
            $rowDimensionSelect->filter('optgroup[label="Clinical care"] option')->count(),
        );
    }

    public function testOpenEditGroupsMetricChoices(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $render = $testComponent->render();
        $metricSelect = $render->crawler()->filter('select[name*="metric"]');
        self::assertGreaterThan(0, $metricSelect->count());
        self::assertGreaterThan(
            0,
            $metricSelect->filter('optgroup[label="Clinical rates"] option')->count(),
        );
        self::assertGreaterThan(
            0,
            $metricSelect->filter('optgroup[label="Counts"] option')->count(),
        );
    }

    public function testOpenEditGroupsHospitalAdditionalTableMetrics(): void
    {
        $testComponent = $this->createHospitalsShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $render = $testComponent->render();
        $field = $render->crawler()->filter('[data-testid="stats-analysis-explorer-additional-table-metrics-field"]');
        self::assertGreaterThan(0, $field->count());
        self::assertGreaterThan(0, $field->filter('fieldset legend')->count());

        $legendTexts = $field->filter('fieldset legend')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $legend): string => trim($legend->text()),
        );
        self::assertContains('Beds', $legendTexts);
    }

    public function testApplyEditChangesChartType(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');
        $formName = $this->formName($testComponent->render());

        $updatedRender = $testComponent
            ->submitForm($this->formPayload($formName, ['chartType' => 'line']))
            ->call('applyEdit')
            ->render();

        $chart = $updatedRender->crawler()->filter('[data-controller="generic-analysis-chart"]');
        if ($chart->count() > 0) {
            $specsRaw = $chart->attr('data-generic-analysis-chart-specs-value');
            self::assertNotNull($specsRaw);
            self::assertStringContainsString('"line"', $specsRaw);
            self::assertSame('line', $chart->attr('data-generic-analysis-chart-default-type-value'));
        } else {
            self::assertSame('line', $testComponent->component()->appliedConfigState['presentation']['chartType'] ?? null);
        }
    }

    public function testApplyEditChangesTimeGrainInAppliedState(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $this->formName($testComponent->render());
        $testComponent
            ->submitForm($this->formPayload($formName, [
                'rowDimension' => 'time',
                'rowGrain' => 'year',
                'columnDimension' => 'gender',
                'columnGrain' => 'total',
            ]))
            ->call('applyEdit');

        self::assertSame('year', $testComponent->component()->appliedConfigState['query']['rows']['grain'] ?? null);
    }

    public function testApplyEditChangesDimensionToGenderWithTotalGrain(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $this->formName($testComponent->render());
        $testComponent
            ->submitForm($this->formPayload($formName, [
                'rowDimension' => 'gender',
                'rowGrain' => 'total',
                'chartType' => 'bar',
            ]))
            ->call('applyEdit');

        self::assertSame('gender', $testComponent->component()->appliedConfigState['query']['rows']['dimension'] ?? null);
        self::assertSame('total', $testComponent->component()->appliedConfigState['query']['rows']['grain'] ?? null);
    }

    public function testApplyEditGenderMonthUsesGroupedBarChart(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $this->formName($testComponent->render());
        $updatedRender = $testComponent
            ->submitForm($this->formPayload($formName, [
                'rowDimension' => 'time',
                'rowGrain' => 'month',
                'columnDimension' => 'gender',
                'columnGrain' => 'total',
                'chartType' => 'grouped_bar',
            ]))
            ->call('applyEdit')
            ->render();

        self::assertSame('grouped_bar', $testComponent->component()->appliedConfigState['presentation']['chartType'] ?? null);
        $chart = $updatedRender->crawler()->filter('[data-controller="generic-analysis-chart"]');
        if ($chart->count() > 0) {
            $specsRaw = $chart->attr('data-generic-analysis-chart-specs-value');
            self::assertNotNull($specsRaw);
            self::assertStringContainsString('"grouped_bar"', $specsRaw);
        }
    }

    public function testDimensionChangeShowsTimeGrainField(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $this->formName($testComponent->render());
        $render = $testComponent
            ->submitForm($this->formPayload($formName, [
                'rowDimension' => 'time',
                'rowGrain' => 'month',
                'columnDimension' => 'urgency',
                'columnGrain' => 'total',
            ]))
            ->call('refreshEditForm')
            ->render();

        self::assertGreaterThan(0, $render->crawler()->filter('[data-testid="stats-analysis-explorer-row-grain-field"]')->count());
        self::assertStringNotContainsString(
            'd-none',
            (string) $render->crawler()->filter('[data-testid="stats-analysis-explorer-row-grain-field"]')->attr('class'),
        );
        self::assertGreaterThan(
            0,
            $render->crawler()->filter('[data-testid="stats-analysis-explorer-row-grain-field"] option[value="total"]')->count(),
        );

        $genderRender = $testComponent
            ->submitForm($this->formPayload($formName, [
                'rowDimension' => 'gender',
                'rowGrain' => 'total',
            ]))
            ->call('refreshEditForm')
            ->render();

        self::assertStringContainsString(
            'd-none',
            (string) $genderRender->crawler()->filter('[data-testid="stats-analysis-explorer-row-grain-field"]')->attr('class'),
        );
    }

    public function testAddingAColumnDefaultsTheTableLayoutToMatrix(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $this->formName($testComponent->render());
        $render = $testComponent
            ->submitForm($this->formPayload($formName, [
                'rowDimension' => 'age_group',
                'rowGrain' => 'total',
                'columnDimension' => 'urgency',
                'columnGrain' => 'total',
            ]))
            ->call('applyEdit')
            ->render();

        self::assertSame('matrix', $testComponent->component()->appliedConfigState['presentation']['tableLayout'] ?? null);
        $layout = $render->crawler()->filter('[data-testid="stats-analysis-explorer-table-layout-field"] option[selected]');
        self::assertGreaterThan(0, $layout->count());
        self::assertSame('matrix', $layout->attr('value'));

        $flatRender = $testComponent
            ->submitForm($this->formPayload($formName, [
                'rowDimension' => 'age_group',
                'rowGrain' => 'total',
                'columnDimension' => 'urgency',
                'columnGrain' => 'total',
                'chartType' => 'grouped_bar',
                'tableLayout' => 'flat',
            ]))
            ->call('applyEdit')
            ->render();

        self::assertSame('flat', $testComponent->component()->appliedConfigState['presentation']['tableLayout'] ?? null);
        $flatLayout = $flatRender->crawler()->filter('[data-testid="stats-analysis-explorer-table-layout-field"] option[selected]');
        self::assertGreaterThan(0, $flatLayout->count());
        self::assertSame('flat', $flatLayout->attr('value'));
    }

    public function testTimeColumnsOfferAllAllocationsGrain(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $this->formName($testComponent->render());
        $render = $testComponent
            ->submitForm($this->formPayload($formName, [
                'rowDimension' => 'urgency',
                'rowGrain' => 'total',
                'columnDimension' => 'time',
                'columnGrain' => 'total',
            ]))
            ->call('refreshEditForm')
            ->render();

        self::assertStringNotContainsString(
            'd-none',
            (string) $render->crawler()->filter('[data-testid="stats-analysis-explorer-column-grain-field"]')->attr('class'),
        );
        self::assertGreaterThan(
            0,
            $render->crawler()->filter('[data-testid="stats-analysis-explorer-column-grain-field"] option[value="total"]')->count(),
        );
        self::assertGreaterThan(
            0,
            $render->crawler()->filter('[data-testid="stats-analysis-explorer-column-grain-field"] option[value="total"][selected]')->count(),
        );
    }

    public function testPageSeparatesContextShelfAndFilters(): void
    {
        $testComponent = $this->createShellComponent();
        $render = $testComponent->render();
        $crawler = $render->crawler();

        self::assertGreaterThan(0, $crawler->filter('[data-testid="stats-analysis-explorer-context-location"]')->count());
        self::assertStringContainsString(
            'All assignments',
            (string) $crawler->filter('[data-testid="stats-analysis-explorer-context-location"]')->text(),
        );
        self::assertGreaterThan(0, $crawler->filter('[data-testid="stats-analysis-explorer-context-period"]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-testid="stats-analysis-explorer-shelf"]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-testid="stats-analysis-explorer-chart-type"]')->count());
        self::assertCount(
            0,
            $crawler->filter('[data-testid="stats-analysis-explorer-edit-drawer"] select[name*="rowDimension"]'),
        );
        self::assertCount(
            0,
            $crawler->filter('[data-testid="stats-analysis-explorer-edit-drawer"] select[name*="chartType"]'),
        );
        self::assertGreaterThan(
            0,
            $crawler->filter('[data-testid="stats-analysis-explorer-edit-section-scope"]')->count(),
        );
        self::assertGreaterThan(
            0,
            $crawler->filter('[data-testid="stats-analysis-explorer-filter-section-demographics"]')->count(),
        );
    }

    public function testApplyEditWithChartRowLimitFromPresentationSection(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $this->formName($testComponent->render());
        $testComponent
            ->submitForm($this->formPayload($formName, [
                'rowDimension' => 'gender',
                'rowGrain' => 'total',
                'chartRowLimit' => '5',
            ]))
            ->call('applyEdit');

        self::assertSame('gender', $testComponent->component()->appliedConfigState['query']['rows']['dimension'] ?? null);
        self::assertSame('5', $testComponent->component()->appliedConfigState['presentation']['chartRowLimit'] ?? null);
    }
}
