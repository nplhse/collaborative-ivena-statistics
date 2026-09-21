<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class ReportEmptyStateTemplateTest extends KernelTestCase
{
    public function testNoSourceDataShowsImportOnlyWhenAllowed(): void
    {
        $html = $this->renderReportEmpty(hasSourceData: false, canImport: true, filterCount: 0);

        self::assertStringContainsString('No allocations imported yet', $html);
        self::assertStringContainsString('href="/import/new"', $html);
        self::assertStringContainsString('target="_top"', $html);
        self::assertStringNotContainsString('Change period or scope', $html);
    }

    public function testNoSourceDataWithoutPermissionOmitsImport(): void
    {
        $html = $this->renderReportEmpty(hasSourceData: false, canImport: false, filterCount: 0);

        self::assertStringContainsString('Open onboarding', $html);
        self::assertStringContainsString('href="/"', $html);
        self::assertStringContainsString('target="_top"', $html);
        self::assertStringNotContainsString('href="/import/new"', $html);
    }

    public function testEmptyPeriodDoesNotSuggestImport(): void
    {
        $html = $this->renderReportEmpty(hasSourceData: true, canImport: true, filterCount: 0);

        self::assertStringContainsString('No allocations for this month', $html);
        self::assertStringContainsString('Change period or scope', $html);
        self::assertStringNotContainsString('href="/import/new"', $html);
    }

    public function testActiveFiltersOfferReset(): void
    {
        $html = $this->renderReportEmpty(hasSourceData: true, canImport: true, filterCount: 2);

        self::assertStringContainsString('No results for the current filters', $html);
        self::assertStringContainsString('href="/statistics/reports/monthly?scope=public"', $html);
        self::assertStringContainsString('Reset filters', $html);
        self::assertStringNotContainsString('href="/import/new"', $html);
    }

    public function testExplorerNoDataOffersContextInsteadOfImport(): void
    {
        $html = $this->renderExplorerEmpty('no_data', true);

        self::assertStringContainsString('No data available for the selected scope and period.', $html);
        self::assertStringContainsString('Change period or scope', $html);
        self::assertStringNotContainsString('href="/import/new"', $html);
    }

    public function testExplorerNoSourceOffersImportWhenAllowed(): void
    {
        $html = $this->renderExplorerEmpty('no_source', true);

        self::assertStringContainsString('No allocations have been imported yet', $html);
        self::assertStringContainsString('href="/import/new"', $html);
        self::assertStringContainsString('target="_top"', $html);
    }

    private function renderReportEmpty(bool $hasSourceData, bool $canImport, int $filterCount): string
    {
        return $this->twig()->render('@Statistics/reports/types/_report_empty.html.twig', [
            'testId' => 'stats-monthly-report-empty',
            'icon' => 'tabler:database',
            'contextTitle' => 'No allocations for this month',
            'contextDescription' => 'There is no allocation data for March 2024 in the selected scope yet.',
            'hasSourceData' => $hasSourceData,
            'canImport' => $canImport,
            'importUrl' => '/import/new',
            'dashboardUrl' => '/statistics',
            'statsFilterDrawer' => ['activeCount' => $filterCount],
            'statsFilterDrawerResetUrl' => '/statistics/reports/monthly?scope=public',
        ]);
    }

    private function renderExplorerEmpty(string $reason, bool $canImport): string
    {
        return $this->twig()->render('@Statistics/analysis_explorer/_empty_state.html.twig', [
            'emptyReason' => $reason,
            'canImport' => $canImport,
        ]);
    }

    private function twig(): Environment
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig;
    }
}
