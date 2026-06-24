<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerChoiceGrouper;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExplorerChoiceGrouperTest extends TestCase
{
    public function testGroupsChoicesInConfiguredOrderAndSortsAlphabeticallyWithinGroup(): void
    {
        $grouper = new ExplorerChoiceGrouper($this->translator());

        $grouped = $grouper->groupChoices(
            [
                AnalysisMetricKey::ShockRate,
                AnalysisMetricKey::CprRate,
                AnalysisMetricKey::AllocationCount,
                AnalysisMetricKey::PrevalenceRate,
            ],
            [
                'stats.analysis_explorer.metric_group.counts',
                'stats.analysis_explorer.metric_group.clinical_rates',
                'stats.analysis_explorer.metric_group.shares',
            ],
            static fn (AnalysisMetricKey $metric): string => $metric->explorerGroupTranslationKey(),
            static fn (AnalysisMetricKey $metric): string => match ($metric) {
                AnalysisMetricKey::AllocationCount => 'Allocations',
                AnalysisMetricKey::CprRate => 'CPR rate',
                AnalysisMetricKey::ShockRate => 'Shock rate',
                AnalysisMetricKey::PrevalenceRate => 'Share within category (%)',
            },
            static fn (AnalysisMetricKey $metric): string => $metric->value,
            'en',
        );

        self::assertSame(
            ['Allocations' => 'allocation_count'],
            $grouped['Counts'],
        );
        self::assertSame(
            ['CPR rate' => 'cpr_rate', 'Shock rate' => 'shock_rate'],
            $grouped['Clinical rates'],
        );
        self::assertSame(
            ['Share within category (%)' => 'prevalence_rate'],
            $grouped['Shares and distribution'],
        );
    }

    public function testSkipsEmptyGroups(): void
    {
        $grouper = new ExplorerChoiceGrouper($this->translator());

        $grouped = $grouper->groupChoices(
            [AnalysisMetricKey::HospitalCount],
            [
                'stats.analysis_explorer.metric_group.counts',
                'stats.analysis_explorer.metric_group.beds',
            ],
            static fn (AnalysisMetricKey $metric): string => $metric->explorerGroupTranslationKey(),
            static fn (AnalysisMetricKey $metric): string => $metric->value,
            static fn (AnalysisMetricKey $metric): string => $metric->value,
            'en',
        );

        self::assertCount(1, $grouped);
        self::assertArrayHasKey('Counts', $grouped);
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => match ($id) {
                'stats.analysis_explorer.metric_group.counts' => 'Counts',
                'stats.analysis_explorer.metric_group.clinical_rates' => 'Clinical rates',
                'stats.analysis_explorer.metric_group.shares' => 'Shares and distribution',
                'stats.analysis_explorer.metric_group.beds' => 'Beds',
                default => $id,
            },
        );

        return $translator;
    }
}
