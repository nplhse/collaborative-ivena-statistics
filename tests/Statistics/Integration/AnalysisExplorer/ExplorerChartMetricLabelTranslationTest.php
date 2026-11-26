<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * The Analysis Explorer chart titles its value axis with `stats.analysis_explorer.metric.<key>` for every
 * chartable metric without a distribution profile (#1408). Each of them needs an EN and a DE message, otherwise
 * the chart falls back to the raw key or to English in the German UI.
 */
final class ExplorerChartMetricLabelTranslationTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, AnalysisMetricKey}>
     */
    public static function chartMetricLabels(): iterable
    {
        $metricKeys = array_unique(
            [...AnalysisMetricKey::allocationsCatalog(), ...AnalysisMetricKey::hospitalsCatalog()],
            \SORT_REGULAR,
        );

        foreach (['en', 'de'] as $locale) {
            foreach ($metricKeys as $metricKey) {
                if (!$metricKey->isChartable() || $metricKey->isDistributionProfile()) {
                    continue;
                }

                yield $locale.' '.$metricKey->value => [$locale, $metricKey];
            }
        }
    }

    #[DataProvider('chartMetricLabels')]
    public function testChartMetricLabelIsTranslated(string $locale, AnalysisMetricKey $metricKey): void
    {
        self::bootKernel();
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);

        // defines() ignores the fallback locale, so a missing DE message is not hidden by the EN one.
        self::assertTrue(
            $translator->getCatalogue($locale)->defines(
                'stats.analysis_explorer.metric.'.$metricKey->value,
                'statistics+intl-icu',
            ),
            sprintf('Missing "%s" translation for metric "%s" in the statistics domain.', $locale, $metricKey->value),
        );
    }
}
