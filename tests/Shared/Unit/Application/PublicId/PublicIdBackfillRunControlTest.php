<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Application\PublicId;

use App\Shared\Application\PublicId\PublicIdBackfillInterruptedException;
use App\Shared\Application\PublicId\PublicIdBackfillRunControl;
use PHPUnit\Framework\TestCase;

final class PublicIdBackfillRunControlTest extends TestCase
{
    public function testRequestStopIsObserved(): void
    {
        $control = new PublicIdBackfillRunControl();

        self::assertFalse($control->isStopRequested());
        self::assertNull($control->getSignal());

        $control->requestStop(\SIGTERM);

        self::assertTrue($control->isStopRequested());
        self::assertSame(\SIGTERM, $control->getSignal());
    }

    public function testThrowIfStopRequestedRaisesException(): void
    {
        $control = new PublicIdBackfillRunControl();
        $control->requestStop(\SIGINT);

        $this->expectException(PublicIdBackfillInterruptedException::class);
        $this->expectExceptionMessage('signal 2');

        $control->throwIfStopRequested();
    }

    public function testThrowIfStopRequestedDoesNothingWhenNotStopped(): void
    {
        $control = new PublicIdBackfillRunControl();

        $control->throwIfStopRequested();

        self::assertFalse($control->isStopRequested());
    }
}
