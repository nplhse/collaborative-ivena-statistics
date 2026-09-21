<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Twig\Components;

use App\Shared\UI\Twig\Components\ActiveFilters;
use PHPUnit\Framework\TestCase;

final class ActiveFiltersTest extends TestCase
{
    public function testHasBadgesIsFalseByDefault(): void
    {
        $component = new ActiveFilters();

        self::assertFalse($component->hasBadges());
    }

    public function testHasBadgesIsTrueWhenItemsExist(): void
    {
        $component = new ActiveFilters();
        $component->badges = [
            ['label' => 'Hospital', 'value' => 'Kiel'],
        ];

        self::assertTrue($component->hasBadges());
    }

    public function testHasBadgesIsFalseForEmptyList(): void
    {
        $component = new ActiveFilters();
        $component->badges = [];

        self::assertFalse($component->hasBadges());
    }
}
