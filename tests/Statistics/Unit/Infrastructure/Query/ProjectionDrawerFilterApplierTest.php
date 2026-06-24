<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\Mapping\StatisticsAgeGroupFilter;
use App\Statistics\Infrastructure\Query\ProjectionDrawerFilterApplier;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ProjectionDrawerFilterApplierTest extends TestCase
{
    private ProjectionDrawerFilterApplier $applier;

    protected function setUp(): void
    {
        $this->applier = new ProjectionDrawerFilterApplier();
    }

    public function testAppliesAggregateAgeGroupFilter(): void
    {
        $qb = $this->createQueryBuilderMock();
        $qb->expects(self::once())
            ->method('andWhere')
            ->with(StatisticsAgeGroupFilter::sqlCondition('p.age', StatisticsAgeGroupFilter::UNDER_18));

        $this->applier->apply($qb, new StatisticsDrawerFilter(
            ageGroup: StatisticsAgeGroupFilter::UNDER_18,
        ));
    }

    public function testAppliesClinicalBooleanFilters(): void
    {
        $qb = $this->createQueryBuilderMock();
        $qb->expects(self::exactly(2))
            ->method('andWhere')
            ->willReturnCallback(function (string $condition) use ($qb): QueryBuilder {
                static $calls = 0;
                match ($calls++) {
                    0 => self::assertSame('p.requiresResus = :drawerRequiresResus', $condition),
                    1 => self::assertSame('p.infectionId IS NOT NULL', $condition),
                    default => self::fail('Unexpected andWhere call'),
                };

                return $qb;
            });
        $qb->expects(self::once())
            ->method('setParameter')
            ->with('drawerRequiresResus', true);

        $this->applier->apply($qb, new StatisticsDrawerFilter(
            requiresResus: true,
            isInfectious: true,
        ));
    }

    /**
     * @return QueryBuilder&MockObject
     */
    private function createQueryBuilderMock(): QueryBuilder
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();

        return $qb;
    }
}
