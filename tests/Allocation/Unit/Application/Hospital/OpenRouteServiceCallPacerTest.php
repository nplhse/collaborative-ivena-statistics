<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital;

use App\Allocation\Application\Hospital\OpenRouteServiceCallPacer;
use PHPUnit\Framework\TestCase;

final class OpenRouteServiceCallPacerTest extends TestCase
{
    public function testSkipsPausesWhenDelayIsDisabled(): void
    {
        $pacer = new OpenRouteServiceCallPacer();
        $started = hrtime(true);

        $pacer->pauseBetweenCalls(true, 0);
        $pacer->pauseForRetryAfter(30, 0);
        $pacer->pauseBetweenCalls(false, 3500);

        $elapsedMs = (hrtime(true) - $started) / 1_000_000;
        self::assertLessThan(50, $elapsedMs);
    }
}
