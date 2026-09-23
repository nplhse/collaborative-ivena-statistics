<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerAssistantCatalog;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use PHPUnit\Framework\TestCase;

final class ExplorerAssistantCatalogTest extends TestCase
{
    public function testCharacteristicAndSuggestionListsStayAvailableToTheAssistant(): void
    {
        self::assertContains(AnalysisDimensionKey::Urgency, ExplorerAssistantCatalog::characteristics());
        self::assertContains(AnalysisDimensionKey::Indication, ExplorerAssistantCatalog::toplistSuggestions());
        self::assertContains(AnalysisDimensionKey::HospitalTier, ExplorerAssistantCatalog::hospitalToplistSuggestions());
        self::assertContains(AnalysisDimensionKey::Hour, ExplorerAssistantCatalog::matrixDimensions());
        self::assertContains(AnalysisDimensionGrain::Month, ExplorerAssistantCatalog::timeGrains());
        self::assertNotContains(AnalysisDimensionGrain::Total, ExplorerAssistantCatalog::timeGrains());
    }
}
