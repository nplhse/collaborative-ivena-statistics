<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Statistics\CaseFlow\Application\DTO\CaseFlowDispatchAreaFlow;
use PHPUnit\Framework\TestCase;

final class CaseFlowDispatchAreaFlowTest extends TestCase
{
    public function testLocalOriginAndInsideDestinationCombineBuckets(): void
    {
        $flow = new CaseFlowDispatchAreaFlow(10, 20, 5, 35, 28.6, 57.1, 14.3, 85.7, 71.4);

        self::assertSame(30, $flow->insideDestinationCases());
        self::assertSame(25, $flow->localOriginCases());
        self::assertTrue($flow->showDestinationSplit);
    }

    public function testHospitalCatchmentOmitsDestinationSplit(): void
    {
        $flow = new CaseFlowDispatchAreaFlow(3, 7, 0, 10, 30.0, 70.0, 0.0, 100.0, 70.0, false);

        self::assertFalse($flow->showDestinationSplit);
        self::assertSame(10, $flow->insideDestinationCases());
        self::assertSame(7, $flow->localOriginCases());
    }
}
