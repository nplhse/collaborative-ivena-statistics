<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Twig\DataTable;

use App\Shared\UI\Twig\DataTable\BadgePalette;
use App\Shared\UI\Twig\DataTable\BadgePresentation;
use PHPUnit\Framework\TestCase;

enum DataTableBadgePaletteTestEnum: string
{
    case Urban = 'Urban';
}

final class BadgePaletteTest extends TestCase
{
    public function testResolvesNamedPaletteAndEnumValue(): void
    {
        $view = (new BadgePalette())->resolve('hospital_location', DataTableBadgePaletteTestEnum::Urban);

        self::assertSame('Urban', $view->label);
        self::assertSame('bg-indigo text-indigo-fg', $view->cssClass);
        self::assertSame(BadgePresentation::Badge, $view->presentation);
    }

    public function testUnknownValueFallsBackToSecondaryBadge(): void
    {
        $view = (new BadgePalette())->resolve('hospital_location', 'Unknown');

        self::assertSame('Unknown', $view->label);
        self::assertSame('bg-secondary text-secondary-fg', $view->cssClass);
    }

    public function testImportStatusUsesStatusPresentationAndAnimatedDot(): void
    {
        $view = (new BadgePalette())->resolve('import_status', 'Running');

        self::assertSame(BadgePresentation::Status, $view->presentation);
        self::assertSame('status status-lime', $view->cssClass);
        self::assertTrue($view->animatedDot);
        self::assertSame('lime', $view->statusColor);
    }

    public function testAllocationUrgencyAcceptsIntegerKeys(): void
    {
        $view = (new BadgePalette())->resolve('allocation_urgency', 1);

        self::assertSame('Emergency Care', $view->label);
        self::assertSame('bg-red text-red-fg', $view->cssClass);
    }
}
