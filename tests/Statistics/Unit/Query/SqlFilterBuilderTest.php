<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Query;

use App\Statistics\Application\Filter\FilterRegistry;
use App\Statistics\Application\Filter\FilterState;
use App\Statistics\Application\Panel\PanelFactory;
use App\Statistics\Infrastructure\Query\SqlFilterBuilder;
use PHPUnit\Framework\TestCase;

final class SqlFilterBuilderTest extends TestCase
{
    public function testBuildsWhereForPresetAndSelectFilter(): void
    {
        $builder = new SqlFilterBuilder(new FilterRegistry());
        $panel = new PanelFactory()->createDistributionPanel('urgency');

        $where = $builder->buildWhere(new FilterState([
            'date_range' => 'last_12_months',
        ]), $panel);

        self::assertStringContainsString('created_at >= :date_from_default', $where['where']);
        self::assertCount(1, $where['params']);
    }
}
