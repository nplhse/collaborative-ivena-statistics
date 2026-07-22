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
        self::assertGreaterThan(
            0,
            $render->crawler()->filter('[data-testid="stats-analysis-explorer-show-percent-field"]')->count(),
        );
    }

    public function testOpenEditKeepsLibraryLinkVisible(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $render = $testComponent->render();
        self::assertGreaterThan(
            0,
            $render->crawler()->filter('[data-testid="stats-analysis-explorer-library-link"]')->count(),
        );
        self::assertSame(
            '/statistics/analysis/library',
            $testComponent->component()->libraryUrl,
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
    }

    public function testEditDrawerAnalysisAndPresentationExpandedByDefault(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $render = $testComponent->render();
        $crawler = $render->crawler();

        self::assertStringContainsString(
            'collapsed',
            $crawler->filter('[data-testid="stats-analysis-explorer-edit-section-scope"]')->attr('class') ?? '',
        );
        self::assertStringContainsString(
            'collapsed',
            $crawler->filter('[data-testid="stats-analysis-explorer-edit-section-period"]')->attr('class') ?? '',
        );
        self::assertStringNotContainsString(
            'collapsed',
            $crawler->filter('[data-testid="stats-analysis-explorer-edit-section-analysis"]')->attr('class') ?? '',
        );
        self::assertStringNotContainsString(
            'collapsed',
            $crawler->filter('[data-testid="stats-analysis-explorer-edit-section-presentation"]')->attr('class') ?? '',
        );
        self::assertStringNotContainsString(
            ' show',
            ' '.$crawler->filter('#analysisExplorerEditScopePanel')->attr('class'),
        );
        self::assertStringNotContainsString(
            ' show',
            ' '.$crawler->filter('#analysisExplorerEditPeriodPanel')->attr('class'),
        );
        self::assertStringContainsString(
            'show',
            $crawler->filter('#analysisExplorerEditAnalysisPanel')->attr('class') ?? '',
        );
        self::assertStringContainsString(
            'show',
            $crawler->filter('#analysisExplorerEditPresentationPanel')->attr('class') ?? '',
        );
        self::assertGreaterThan(
            0,
            $crawler->filter('[data-testid="stats-analysis-explorer-edit-section-analysis"] .analysis-explorer-accordion-state-icon--expanded')->count(),
        );
        self::assertGreaterThan(
            0,
            $crawler->filter('[data-testid="stats-analysis-explorer-edit-section-scope"] .analysis-explorer-accordion-state-icon--collapsed')->count(),
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
