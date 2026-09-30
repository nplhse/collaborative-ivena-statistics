<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Twig\DataTable;

use App\Shared\UI\Twig\DataTable\BadgePalette;
use App\Shared\UI\Twig\DataTable\BadgePresentation;
use PHPUnit\Framework\TestCase;

final class BadgePaletteTest extends TestCase
{
    public function testResolvesNamedPaletteAndEnumValue(): void
    {
        $view = new BadgePalette()->resolve('hospital_location', DataTableBadgePaletteTestEnum::Urban);

        self::assertSame('Urban', $view->label);
        self::assertSame('bg-indigo text-indigo-fg', $view->cssClass);
        self::assertSame(BadgePresentation::Badge, $view->presentation);
    }

    public function testUnknownValueFallsBackToSecondaryBadge(): void
    {
        $view = new BadgePalette()->resolve('hospital_location', 'Unknown');

        self::assertSame('Unknown', $view->label);
        self::assertSame('bg-secondary text-secondary-fg', $view->cssClass);
    }

    public function testImportStatusUsesStatusPresentationAndAnimatedDot(): void
    {
        $view = new BadgePalette()->resolve('import_status', 'Running');

        self::assertSame(BadgePresentation::Status, $view->presentation);
        self::assertSame('status status-lime', $view->cssClass);
        self::assertTrue($view->animatedDot);
        self::assertSame('lime', $view->statusColor);
    }

    public function testAllocationUrgencyAcceptsIntegerKeys(): void
    {
        $view = new BadgePalette()->resolve('allocation_urgency', 1);

        self::assertSame('Emergency Care', $view->label);
        self::assertSame('bg-red text-red-fg', $view->cssClass);
    }

    public function testClosureCareLevelUsesAllocationUrgencyColors(): void
    {
        $palette = new BadgePalette();

        self::assertSame('bg-red-lt text-red', $palette->resolve('closure_care_level', 'emergency')->cssClass);
        self::assertSame('bg-yellow-lt text-yellow', $palette->resolve('closure_care_level', 'inpatient')->cssClass);
        self::assertSame('bg-green-lt text-green', $palette->resolve('closure_care_level', 'outpatient')->cssClass);
    }

    public function testClosureReasonsUseQuietGrayVariants(): void
    {
        $palette = new BadgePalette();

        self::assertSame('bg-gray-lt text-gray', $palette->resolve('closure_reason', 'no_bed_capacity')->cssClass);
        self::assertSame('bg-secondary-lt text-secondary', $palette->resolve('closure_reason', 'emergency_department_overload')->cssClass);
        self::assertSame('bg-dark-lt text-dark', $palette->resolve('closure_reason', 'technical_fault')->cssClass);
        self::assertSame('bg-gray-dark-lt text-gray-dark', $palette->resolve('closure_reason', 'operating_room_notice')->cssClass);
        self::assertSame('bg-gray-muted-lt text-secondary', $palette->resolve('closure_reason', 'not_specified')->cssClass);
    }

    public function testStringableAndUnknownValuesNormalizeToLabel(): void
    {
        $urban = new BadgePalette()->resolve('hospital_location', new DataTableBadgePaletteStringable('Urban'));
        self::assertSame('Urban', $urban->label);
        self::assertSame('bg-indigo text-indigo-fg', $urban->cssClass);

        $empty = new BadgePalette()->resolve('hospital_location', false);
        self::assertSame('', $empty->label);
        self::assertSame('bg-secondary text-secondary-fg', $empty->cssClass);
    }
}
