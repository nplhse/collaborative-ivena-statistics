<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerTitleFactory;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExplorerTitleFactoryTest extends TestCase
{
    public function testTitleForGenderTotalUsesSummedTitle(): void
    {
        $factory = new ExplorerTitleFactory($this->translator([
            'stats.analysis_explorer.allocations_by_gender' => 'Allocations by gender',
        ]));

        self::assertSame(
            'Allocations by gender',
            $factory->titleFor(AnalysisDimensionKey::Gender, AnalysisDimensionGrain::Total),
        );
    }

    public function testTitleForGenderMonthUsesOverTimeTitle(): void
    {
        $factory = new ExplorerTitleFactory($this->translator([
            'stats.analysis_explorer.allocations_by_gender_over_time' => 'Allocations by gender over time',
        ]));

        self::assertSame(
            'Allocations by gender over time',
            $factory->titleFor(AnalysisDimensionKey::Gender, AnalysisDimensionGrain::Month),
        );
    }

    /**
     * @param array<string, string> $map
     */
    private function translator(array $map): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => $map[$id] ?? $id,
        );

        return $translator;
    }
}
