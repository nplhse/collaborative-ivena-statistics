<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Twig\Components;

use App\Shared\UI\Twig\Components\FilterDrawerTrigger;
use PHPUnit\Framework\TestCase;

final class FilterDrawerTriggerTest extends TestCase
{
    public function testClearButtonRequiresActiveFiltersAndResetUrl(): void
    {
        $component = new FilterDrawerTrigger();
        $component->drawerId = 'allocation-filters';
        $component->activeCount = 2;
        $component->resetUrl = '/explore/allocation';

        self::assertTrue($component->shouldShowClearButton());
    }

    public function testClearButtonIsHiddenWithoutActiveFilters(): void
    {
        $component = new FilterDrawerTrigger();
        $component->drawerId = 'allocation-filters';
        $component->activeCount = 0;
        $component->resetUrl = '/explore/allocation';

        self::assertFalse($component->shouldShowClearButton());
    }

    public function testClearButtonIsHiddenWithoutResetUrl(): void
    {
        $component = new FilterDrawerTrigger();
        $component->drawerId = 'allocation-filters';
        $component->activeCount = 2;
        $component->resetUrl = null;

        self::assertFalse($component->shouldShowClearButton());
    }

    public function testClearButtonIsHiddenForEmptyResetUrl(): void
    {
        $component = new FilterDrawerTrigger();
        $component->drawerId = 'allocation-filters';
        $component->activeCount = 2;
        $component->resetUrl = '';

        self::assertFalse($component->shouldShowClearButton());
    }
}
