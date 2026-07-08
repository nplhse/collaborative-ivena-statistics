<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerEditFormFilterFieldMapper;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use PHPUnit\Framework\TestCase;

final class ExplorerEditFormFilterFieldMapperTest extends TestCase
{
    private ExplorerEditFormFilterFieldMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ExplorerEditFormFilterFieldMapper();
    }

    public function testMergeSubmittedFiltersCoercesScalarValues(): void
    {
        $merged = $this->mapper->mergeSubmittedFilters(new ExplorerEditFormData(), [
            'filterDepartmentId' => '10',
            'filterUrgency' => '2',
            'filterAgeGroup' => 'under_18',
            'filterResus' => '1',
            'filterIndicationId' => '5',
            'filterIndicationGroupId' => '3',
        ]);

        self::assertSame(10, $merged->filterDepartmentId);
        self::assertSame(2, $merged->filterUrgency);
        self::assertSame('under_18', $merged->filterAgeGroup);
        self::assertTrue($merged->filterResus);
        self::assertSame(5, $merged->filterIndicationId);
        self::assertSame(3, $merged->filterIndicationGroupId);
    }

    public function testMergeSubmittedFiltersKeepsCurrentWhenKeyMissing(): void
    {
        $current = new ExplorerEditFormData(filterUrgency: 1, filterIndicationId: 7);

        $merged = $this->mapper->mergeSubmittedFilters($current, []);

        self::assertSame(7, $merged->filterIndicationId);
    }
}
