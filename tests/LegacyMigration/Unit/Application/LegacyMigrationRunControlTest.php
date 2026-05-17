<?php

declare(strict_types=1);

namespace App\Tests\LegacyMigration\Unit\Application;

use App\LegacyMigration\Application\Exception\LegacyMigrationInterruptedException;
use App\LegacyMigration\Application\Service\LegacyMigrationRunControl;
use PHPUnit\Framework\TestCase;

final class LegacyMigrationRunControlTest extends TestCase
{
    public function testThrowsWhenStopRequested(): void
    {
        $control = new LegacyMigrationRunControl();
        $control->requestStop(\SIGINT);

        $this->expectException(LegacyMigrationInterruptedException::class);
        $control->throwIfStopRequested();
    }

    public function testDoesNotThrowWhenNotStopped(): void
    {
        $control = new LegacyMigrationRunControl();
        $control->throwIfStopRequested();

        self::assertFalse($control->isStopRequested());
    }
}
