<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\Backup;

use App\Storage\Application\Backup\MigrationState;
use PHPUnit\Framework\TestCase;

final class MigrationStateTest extends TestCase
{
    public function testTellsAnOlderFromANewerBackup(): void
    {
        $same = MigrationState::compare(['V1', 'V2'], ['V1', 'V2']);
        self::assertFalse($same->isOlder());
        self::assertFalse($same->isNewer());

        $older = MigrationState::compare(['V1'], ['V1', 'V2', 'V3']);
        self::assertTrue($older->isOlder());
        self::assertFalse($older->isNewer());
        self::assertSame(['V2', 'V3'], $older->pending);

        // A backup of another branch can be both: it misses V2 and holds V9 the code does not know.
        $other = MigrationState::compare(['V1', 'V9'], ['V1', 'V2']);
        self::assertTrue($other->isNewer());
        self::assertSame(['V9'], $other->unknown);
        self::assertSame(['V2'], $other->pending);
    }
}
