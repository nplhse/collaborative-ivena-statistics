<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericHospitalScopeSqlFilter;
use PHPUnit\Framework\TestCase;

final class GenericHospitalScopeSqlFilterTest extends TestCase
{
    private GenericHospitalScopeSqlFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new GenericHospitalScopeSqlFilter();
    }

    public function testPublicScopeHasNoHospitalIdFilter(): void
    {
        [$conditions, $params] = $this->filter->applyHospitalScope(StatisticsScopeCriteria::public());

        self::assertSame(['1 = 1'], $conditions);
        self::assertSame([], $params);
    }

    public function testHospitalIdsScope(): void
    {
        [$conditions, $params] = $this->filter->applyHospitalScope(new StatisticsScopeCriteria([5, 9]));

        self::assertContains('h.id IN (:scope_hospital_ids)', $conditions);
        self::assertSame([5, 9], $params['scope_hospital_ids']);
    }

    public function testEmptyHospitalIdsScopeIsUnsatisfiable(): void
    {
        [$conditions] = $this->filter->applyHospitalScope(new StatisticsScopeCriteria([]));

        self::assertContains('1 = 0', $conditions);
    }
}
