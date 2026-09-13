<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosedDepartmentAssignments;

use App\Statistics\ClosedDepartmentAssignments\Application\ClosedDepartmentNamedCard;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentNamedCardView;
use PHPUnit\Framework\TestCase;

final class ClosedDepartmentNamedCardTest extends TestCase
{
    public function testRankingCardsExcludeDispatchArea(): void
    {
        $cards = ClosedDepartmentNamedCard::rankingCards();

        self::assertCount(6, $cards);
        self::assertNotContains(ClosedDepartmentNamedCard::DispatchArea, $cards);
        self::assertSame('department', $cards[0]->sliceKind());
        self::assertSame('dispatch_area', ClosedDepartmentNamedCard::DispatchArea->sliceKind());
        self::assertSame('top_diagnoses', ClosedDepartmentNamedCard::Indication->topListReport());
        self::assertNull(ClosedDepartmentNamedCard::DispatchArea->topListReport());
    }

    public function testNamedCardViewKeepsRowsWhenAddingTopListUrl(): void
    {
        $view = new ClosedDepartmentNamedCardView(ClosedDepartmentNamedCard::Indication, []);

        $withUrl = $view->withTopListUrl('/statistics/top-lists/top_diagnoses');

        self::assertSame('stats.closed_department.section.indications', $withUrl->titleKey());
        self::assertSame('stats-closed-department-indications', $withUrl->testId());
        self::assertSame('/statistics/top-lists/top_diagnoses', $withUrl->topListUrl);
        self::assertNull($view->topListUrl);
    }
}
