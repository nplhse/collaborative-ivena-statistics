<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsContextFactory;
use PHPUnit\Framework\TestCase;

final class StatisticsContextFactoryTest extends TestCase
{
    public function testCreatesContextWithAllRelevantFields(): void
    {
        $factory = new StatisticsContextFactory();
        $filter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::All,
        );

        $context = $factory->create(null, $filter, null, 'rows', 'cols', 'measure');

        self::assertNull($context->user);
        self::assertSame($filter, $context->filter);
        self::assertSame('rows', $context->pivotRows);
        self::assertSame('cols', $context->pivotCols);
        self::assertSame('measure', $context->pivotMeasure);
    }
}
