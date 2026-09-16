<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Insights;

use App\Statistics\Application\Insights\InsightPopulationFilter;
use PHPUnit\Framework\TestCase;

final class InsightPopulationFilterTest extends TestCase
{
    public function testRejectsUnknownProjectionColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hospital_id');

        InsightPopulationFilter::of('hospital_id', [1]);
    }

    public function testDeduplicatesIdsAndBuildsPredicates(): void
    {
        $filter = InsightPopulationFilter::indications([3, 1, 1, 2]);

        self::assertSame('indication_normalized_id', $filter->column);
        self::assertSame([3, 1, 2], $filter->ids);
        self::assertFalse($filter->isEmpty());
        self::assertSame('indication_normalized_id IN (:subject_ids)', $filter->sqlInPredicate());
        self::assertSame('asp.indication_normalized_id IN (:subject_ids)', $filter->sqlInPredicate('subject_ids', 'asp'));
        self::assertSame(
            '(indication_normalized_id IS NULL OR indication_normalized_id NOT IN (:subject_ids))',
            $filter->sqlBaselinePredicate(),
        );
    }

    public function testEmptyIdListIsEmpty(): void
    {
        $filter = InsightPopulationFilter::of('speciality_id', []);

        self::assertTrue($filter->isEmpty());
        self::assertSame([], $filter->ids);
    }
}
