<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application;

use App\Import\Application\Exception\ImportRequeueInterruptedException;
use App\Import\Application\Service\ImportRequeueRunControl;
use PHPUnit\Framework\TestCase;

final class ImportRequeueRunControlTest extends TestCase
{
    public function testThrowIfStopRequestedThrowsWhenStopWasRequested(): void
    {
        $control = new ImportRequeueRunControl();
        $control->requestStop(15);

        $this->expectException(ImportRequeueInterruptedException::class);
        $control->throwIfStopRequested();
    }

    public function testThrowIfStopRequestedDoesNothingWhenNotStopped(): void
    {
        $control = new ImportRequeueRunControl();
        $control->throwIfStopRequested();

        self::assertFalse($control->isStopRequested());
    }
}
