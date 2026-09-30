<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Application\DataTable;

use App\Shared\Application\DataTable\DataTablePreferenceState;
use PHPUnit\Framework\TestCase;

final class DataTablePreferenceStateTest extends TestCase
{
    public function testVisibleOrderedKeysFollowConfiguredOrderAndSkipHiddenColumns(): void
    {
        $state = new DataTablePreferenceState(
            ['startsAt', 'event', 'hospital', 'actualMinutes'],
            ['hospital', 'event', 'endsAt', 'startsAt', 'actualMinutes'],
            25,
        );

        self::assertSame(
            ['hospital', 'event', 'startsAt', 'actualMinutes'],
            $state->visibleOrderedKeys(),
        );
    }
}
